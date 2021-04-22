<?php

class Anonymize extends SS_Object
{
    private static $anonymize_config = [];

    private $default_yml_fixture = ['silverstripe-anonymizer/_config/default_anonymize.yml'];
    
    /**
     * @var array
     */
    private $tables = [];

    /**
     * @var array
     */
    private $settings = [];


    /**
     * Key = column type
     * Value = function name to be called
     * @var string[]
     */
    private $column_types = [
        'StringFields' => 'anonymizeStringColumns',
        'NullFields' => 'setNullColumns',
        'EmailFields' => 'anonymizeEmailColumns',
    ];

    /**
     * @throws Exception
     * @var bool
     *
     */
    public function anonymizeRecords(): bool
    {
        if (!(Director::isDev() || Director::isTest())) {
            throw new \Exception('anonymizeRecords can only be run in development or test environments');
        }
        $fixtureFiles = self::config()->get('anonymize_config');
        if (empty($fixtureFiles)) {
            $fixtureFiles = $this->default_yml_fixture;
        }
        if (!is_array($fixtureFiles)) {
            $fixtureFiles = [$fixtureFiles];
        }
        foreach ($fixtureFiles as $fixtureFile) {
            $fixture = new YamlFixture($fixtureFile);
            self::log(
                sprintf("Using yml fixture file loaded from '%s'.", $fixtureFile)
            );
            self::log("------------------------", 0);
            $parser = new Spyc();
            $anonymizeConfig = $parser->loadFile($fixture->getFixtureFile());
            $this->tables = isset($anonymizeConfig['Tables']) ? $anonymizeConfig['Tables'] : [];
            $this->settings = isset($anonymizeConfig['Settings']) ? $anonymizeConfig['Settings'] : [];

            foreach ($this->tables as $tableName => $tableConfig) {
                $this->anonymizeTableRecords($tableName, $tableConfig);
            }
            $fixture = null;
        }
        return true;
    }

    private function anonymizeTableRecords($table, $config)
    {
        self::log(sprintf("Anonymizing table '%s'.", $table));
        $object = Injector::inst()->get($table);
        if ($object) {
            $query = sprintf("UPDATE `%s` SET", $table);
            $set = [];
            if (isset($config['Columns']) && $this->hasValidFields($object, $config)) {
                self::log(sprintf("Anonymize settings for '%s' are valid.", $table), 1);
                foreach ($this->column_types as $columnType => $columnFunction) {
                    if (isset($config['Columns'][$columnType])) {
                        if (isset($config['CustomFunctions'])) {
                            $columns = $this->filterCustomFunctions(
                                $config['Columns'][$columnType],
                                $config['CustomFunctions']
                            );
                        }
                        $set = array_merge($set, $this->$columnFunction($columns));
                    }
                }
            }
            foreach ($config['CustomFunctions'] as $fieldName => $functionDetails) {
                self::log(sprintf("Custom function is configured for '%s' field.", $fieldName), 1);
                if (
                    isset($functionDetails['FunctionName']) &&
                    $this->hasMethod($functionDetails['FunctionName'])
                ) {
                    $functionName = $functionDetails['FunctionName'];
                    $variables = isset($functionDetails['Variables']) ? $functionDetails['Variables'] : [];
                    $set[] = $this->$functionName($fieldName, $variables);
                }
            }

            if ($set) {
                $query .= implode(', ', $set);

                if (isset($config['Exclude'])) {
                    self::log(sprintf("Settings exist to exclude certain records from being anonymized"), 1);
                    $query .= ' WHERE ';
                    foreach ($config['Exclude'] as $excludeColumn => $excludeVals) {
                        $excludeVals = implode('\',\'', $excludeVals);
                        self::log(sprintf("Excluding records where '%s' in ['%s']", $excludeColumn, $excludeVals), 2);
                        $query .= sprintf(
                            "%s NOT IN ('%s') AND ",
                            $excludeColumn,
                            $excludeVals
                        );
                    }
                    $query = substr($query, 0, -4);
                }
                self::log(sprintf("Executing query to anonymize '%s'", $table), 2);

                $result = DB::query($query);
            } else {
                self::log(sprintf("Config settings for '%s' will result in no changes, SQL execution skipped", $table), 2);
            }
        } else {
            self::log(sprintf("'%s' is not a valid Object for this project.", $table));
        }
        self::log("------------------------", 0);
    }

    /**
     * @param $stringArr
     * @return array
     */
    private function anonymizeStringColumns($stringArr): array
    {
        $setString = [];
        foreach ($stringArr as $column) {
            self::log(sprintf("Anonymizing '%s' String column", $column), 2);
            $setString[] = sprintf(" %s = CONCAT('%s',%s)", $column, $column, 'ID');
        }
        return $setString;
    }

    /**
     * sets an email address field to [TableName][ID]@[Domain]
     * Domain can be configured in the YML config, if not set a default is provided.
     * This should be used with caution as depending on the websites settings,
     * emails may be sent to these non-existent emails.
     * Recommend using the custom function `singleEmailAddress` as provided, see readme for example setup.
     *
     * @param $stringArr
     * @return array
     */
    private function anonymizeEmailColumns($stringArr): array
    {
        $setString = [];
        $domain = $this->settings['EmailField']['Domain'] ?: 'anonymize.anon';
        foreach ($stringArr as $column) {
            self::log(sprintf("Anonymizing '%s' Email column", $column), 2);
            $setString[] = sprintf(" %s = CONCAT('%s',%s,'@','%s')", $column, $column, 'ID', $domain);
        }
        return $setString;
    }

    /**
     * set all 'Null' field values to NULL
     *
     * @param $stringArr
     * @return array
     */
    private function setNullColumns($stringArr): array
    {
        $setString = [];
        foreach ($stringArr as $column) {
            self::log(sprintf("Setting '%s' column value to NULL", $column), 2);
            $setString[] = sprintf(" %s = NULL", $column);
        }
        return $setString;
    }

    /**
     * sets all email addresses to a single mailbox.
     * uses the + character in the email address so that testers can still differentiate between Member accounts
     *
     * @param $column
     * @param $functionVariables
     * @return string
     */
    private function singleEmailAddress($column, $functionVariables): string
    {
        $mailbox = $functionVariables['Mailbox'] ?: 'no-reply';
        $domain = $this->settings['EmailField']['Domain'] ?: 'anonymize.anon';
        self::log(sprintf("Setting Email field '%s' to be sent to a single email address", $column), 2);
        return sprintf(
            " %s = CONCAT('%s','+','%s',%s,'@','%s')",
            $column,
            $mailbox,
            strtolower($column),
            'ID',
            $domain
        );
    }

    private function generateRandomNumberString($column, $functionVariables): string
    {
        $length = isset($functionVariables['Length']) ? $functionVariables['Length'] : 9;

        /*
         * build the min and max numbers to be used by the SQL function to generate a random phone number
         * if $length is set to 3, $min = 100 and $max = 999
         */
        $min = '1';
        $max = '9';
        for ($i = 0; $i < $length - 1; $i++) {
            $min .= '0';
            $max .= '9';
        }
        self::log(sprintf("Anonymizing '%s' to a random string of numbers", $column), 2);
        return sprintf(
            " %s = FLOOR(RAND()*(%s-%s+1))+%s",
            $column,
            $max,
            $min,
            $min
        );
    }

    private function filterCustomFunctions($columnArr, $customFunctions): array
    {
        foreach ($columnArr as $index => $column) {
            if (isset($customFunctions[$column])) {
                self::log(
                    sprintf(
                        "Column '%s' has a custom function set, removing from default column type functionality.",
                        $column
                    )
                );
                unset($columnArr[$index]);
            }
        }
        return $columnArr;
    }

    private function hasValidFields($object, $tableConfig): bool
    {
        if (isset($tableConfig['Columns'])) {
            foreach ($tableConfig['Columns'] as $type => $columns) {
                foreach ($columns as $column) {
                    if (!$object->hasField($column)) {
                        self::log(
                            sprintf("Column '%s' does not exist, unable to anonymize this table.", $column),
                            1
                        );
                        return false;
                    }
                }
            }
        }

        if (isset($tableConfig['Exclude'])) {
            foreach ($tableConfig['Exclude'] as $column => $settings) {
                if (!$object->hasField($column)) {
                    self::log(
                        sprintf("Exclude column '%s' does not exist, unable to anonymize this table.", $column),
                        1
                    );
                    return false;
                }
            }
        }

        if (isset($tableConfig['CustomFunctions'])) {
            foreach ($tableConfig['CustomFunctions'] as $column => $settings) {
                if (!$object->hasField($column)) {
                    self::log(
                        sprintf(
                            "Custom function column '%s' does not exist, unable to anonymize this table.",
                            $column
                        ),
                        1
                    );
                    return false;
                }
            }
        }
        return true;
    }

    protected static function log($msg, $indent = 0)
    {
        // Let's hide the logs when running tests
        if (!SapphireTest::is_running_test()) {
            if ($indent > 0) {
                for ($i = 0; $i < $indent; $i++) {
                    echo Director::is_cli() ? '-' : '&nbsp;&nbsp;';
                }
            }
            echo $msg . (Director::is_cli() ? PHP_EOL : '<br>');
        }
    }
}
