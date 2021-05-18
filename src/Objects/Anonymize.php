<?php
namespace ScottNZ\Anonymizer\Objects;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use Symfony\Component\Yaml\Parser;

class Anonymize extends DataObject
{

    /**
     * @config
     *
     * @var array
     */
    private static $anonymize_config = [];

    /**
     * @var string[]
     */
    private $default_yml_fixture = ['vendor/scott-nz/silverstripe-anonymizer/_config/default_anonymize.yml'];


    /**
     * @var array
     */
    private $dataObjects = [];

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
     * @return bool
     * @throws Exception
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
            $fixture->getFixtureString();
            $parser = new Parser();
            $anonymizeConfig = $parser->parseFile($fixture->getFixtureFile());
            $this->dataObjects = $anonymizeConfig['DataObjects'] ?? [];
            $this->settings = $anonymizeConfig['Settings'] ?? [];

            foreach ($this->dataObjects as $tableName => $tableConfig) {
                $this->anonymizeDataObjectRecords($tableName, $tableConfig);
            }
            $fixture = null;
        }
        return true;
    }

    private function anonymizeDataObjectRecords(string $objectName, array $config)
    {
        self::log(sprintf("Anonymizing DataObject '%s'.", $objectName));
        $object = Injector::inst()->get($objectName);
        if ($object) {
            $table = $object->baseTable();
            $query = sprintf("UPDATE `%s` SET", $table);
            $set = [];
            if (isset($config['Columns']) && $this->hasValidFields($object, $config)) {
                self::log(sprintf("Anonymize settings for '%s' are valid.", $table), 1);

                foreach ($this->column_types as $columnType => $columnFunction) {
                    if (isset($config['Columns'][$columnType])) {
                        if (
                            isset($config['CustomFieldFunctions'])
                            && isset($config['CustomFieldFunctions']['Column'])
                        ) {
                            $columns = $this->filterCustomColumnFunctions(
                                $config['Columns'][$columnType],
                                $config['CustomFieldFunctions']['Column']
                            );
                            $set = array_merge($set, $this->$columnFunction($columns));
                        }
                    }
                }
            }
            if (isset($config['CustomFieldFunctions']) && isset($config['CustomFieldFunctions']['Column'])) {
                foreach ($config['CustomFieldFunctions']['Column'] as $fieldName => $functionDetails) {
                    self::log(sprintf("Custom column function is configured for '%s' field.", $fieldName), 1);
                    if (
                        isset($functionDetails['FunctionName']) &&
                        $this->hasMethod($functionDetails['FunctionName'])
                    ) {
                        $functionName = $functionDetails['FunctionName'];
                        $variables = isset($functionDetails['Variables']) ? $functionDetails['Variables'] : [];
                        $set[] = $this->$functionName($fieldName, $variables);
                    }
                }
            }

            if ($set) {
                $query .= implode(', ', $set);
                $where = [];
                if (isset($config['Exclude'])) {
                    self::log(sprintf("Settings exist to exclude certain records from being anonymized"), 1);
                    foreach ($config['Exclude'] as $excludeColumn => $excludeVals) {
                        $excludeVals = implode('\',\'', $excludeVals);
                        self::log(sprintf("Excluding records where '%s' in ['%s']", $excludeColumn, $excludeVals), 2);
                        $where[] = sprintf(
                            "%s NOT IN ('%s')",
                            $excludeColumn,
                            $excludeVals
                        );
                    }
                }

                if (isset($config['CustomFieldFunctions']) && isset($config['CustomFieldFunctions']['Exclude'])) {
                    foreach ($config['CustomFieldFunctions']['Exclude'] as $fieldName => $functionDetails) {
                        self::log(sprintf("Custom exclude function is configured on '%s' field.", $fieldName), 1);
                        if (
                            isset($functionDetails['FunctionName']) &&
                            $this->hasMethod($functionDetails['FunctionName'])
                        ) {
                            $functionName = $functionDetails['FunctionName'];
                            $variables = isset($functionDetails['Variables']) ? $functionDetails['Variables'] : [];
                            $where[] = $this->$functionName($fieldName, $variables);
                        }
                    }
                }
                if ($where) {
                    $query .= " WHERE " . implode(' AND ', $where);
                }

                self::log(sprintf("Executing query to anonymize '%s'", $table), 2);

                try {
                    $result = DB::query($query);
                } catch (DatabaseException $e) {
                    self::log(sprintf("SQL ERROR: unable to execute anonymize query on  '%s'", $table), 0);
                }
            } else {
                self::log(
                    sprintf("Config settings for '%s' will result in no changes, SQL execution skipped", $table),
                    2
                );
            }
        } else {
            self::log(sprintf("'%s' is not a valid Object for this project.", $objectName));
        }
        self::log("------------------------", 0);
    }

    /**
     * @param array $stringArr
     * @return array
     */
    private function anonymizeStringColumns(array $stringArr): array
    {
        $setString = [];
        self::log("Anonymizing the following String columns:", 2);

        foreach ($stringArr as $column) {
            self::log($column, 3);
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
     * @param array $stringArr
     * @return array
     */
    private function anonymizeEmailColumns(array $stringArr): array
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
     * @param array $stringArr
     * @return array
     */
    private function setNullColumns(array $stringArr): array
    {
        $setString = [];
        self::log("Setting the following columns to NULL:", 2);

        foreach ($stringArr as $column) {
            self::log($column, 3);

            $setString[] = sprintf(" %s = NULL", $column);
        }
        return $setString;
    }

    /**
     * sets all email addresses to a single mailbox.
     * uses the + character in the email address so that testers can still differentiate between Member accounts
     *
     * @param string $column
     * @param array $functionVariables
     * @return string
     */
    private function singleEmailAddress(string $column, array $functionVariables): string
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

    /**
     * @param string $column
     * @param array $functionVariables
     * @return string
     */
    private function generateRandomNumberString(string $column, array $functionVariables): string
    {
        $length = $functionVariables['Length'] ?? 9;

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

    /**
     * @param string $column
     * @param array $functionVariables
     * @return string
     */
    private function excludeAdministrators(string $column, array $functionVariables): string
    {
        $admins = Group::get()->filter(['Code' => 'administrators'])->first();
        $members = $admins->Members()->Column('ID');
        return sprintf(" %s NOT IN (%s)", $column, implode(',', $members));
    }

    /**
     * @param array $columnArr
     * @param array $CustomColumnFunctions
     * @return array
     */
    private function filterCustomColumnFunctions(array $columnArr, array $CustomColumnFunctions): array
    {
        foreach ($columnArr as $index => $column) {
            if (isset($CustomColumnFunctions[$column])) {
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

    /**
     * @param DataObject $object
     * @param $tableConfig
     * @return bool
     */
    private function hasValidFields(DataObject $object, array $tableConfig): bool
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

        if (isset($tableConfig['CustomColumnFunctions'])) {
            foreach ($tableConfig['CustomColumnFunctions'] as $column => $settings) {
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

    /**
     * @param string $msg
     * @param int $indent
     */
    protected static function log(string $msg, int $indent = 0)
    {
        if ($indent > 0) {
            for ($i = 0; $i < $indent; $i++) {
                echo Director::is_cli() ? '--' : '&nbsp;&nbsp;&nbsp;&nbsp;';
            }
        }
        echo $msg . (Director::is_cli() ? PHP_EOL : '<br>');
    }
}
