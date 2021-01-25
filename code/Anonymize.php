<?php

/**
 * @package populate
 */
class Anonymize extends SS_Object
{

    /**
     * @config
     *
     * @var array
     */
    private static $include_yaml_fixtures = [];


    /**
     * @var array
     */
    private $tables = [];

    /**
     * @var array
     */
    private $settings = [];

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

        foreach (self::config()->get('include_yaml_fixtures') as $fixtureFile) {
            $fixture = new YamlFixture($fixtureFile);

            $parser = new Spyc();
            $anonymizeConfig = $parser->loadFile($fixture->getFixtureFile());
            $this->tables = $anonymizeConfig['Tables'] ?: [];
            $this->settings = $anonymizeConfig['Settings'] ?: [];

            foreach ($this->tables as $tableName => $tableConfig) {
                $object = Injector::inst($tableName);
                if ($object) {
                    if( isset($tableConfig['Columns'])) {
                        $query = sprintf("UPDATE `%s` SET", $tableName);
                        $set = [];
                        if (isset($tableConfig['Columns']['String'])) {
                            $set = array_merge($set, $this->anonymizeStringColumns($tableConfig['Columns']['String']));

                        }
                        if (isset($tableConfig['Columns']['Email'])) {
                            $set = array_merge($set, $this->anonymizeEmailColumns($tableConfig['Columns']['Email']));
                        }

                        $query .= implode(', ', $set);

                        if( isset($tableConfig['Exclude'])) {
                            $query .= ' WHERE ';
                            foreach ($tableConfig['Exclude'] as $excludeColumn => $excludeVals) {
                                $query .= sprintf(
                                    "%s NOT IN ('%s') AND ",
                                    $excludeColumn,
                                    implode('\',\'', $excludeVals)
                                );
                            }
                            $query = substr($query, 0, -4);
                        }
                    }
                }
                echo $query;
                exit;
            }
            $fixture = null;
        }

        return true;
    }

    /**
     * @param $stringArr
     * @return array
     */
    private function anonymizeStringColumns($stringArr): array
    {
        $setString = [];
        foreach ($stringArr as $column) {
            $setString[] =  sprintf(" %s = CONCAT('%s',%s)", $column, $column, 'ID');
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
        if (isset($this->settings['Email']['AnonymizeFunction']) && method_exists($this, $this->settings['Email']['AnonymizeFunction']['Name'])) {
            $functionName = $this->settings['Email']['AnonymizeFunction']['Name'];
            $mailbox = $this->settings['Email']['AnonymizeFunction']['Variables']['Mailbox'] ?: 'no-reply';
            return $this->$functionName($stringArr, $mailbox);
        } else {
            $setString = [];
            $domain = $this->settings['Email']['Domain'] ?: 'anonymize.anon';
            foreach ($stringArr as $column) {
                $setString[] =  sprintf(" %s = CONCAT('%s',%s,'@','%s')", $column, $column, 'ID', $domain);
            }
            return $setString;
        }
    }

    /**
     * sets all email addresses to a single mailbox.
     * uses the + character in the email address so that testers can still differentiate between Member accounts
     *
     * @param $stringArr
     * @param $mailbox
     * @return array
     */
    private function singleEmailAddress($stringArr, $mailbox): array
    {
        $setString = [];
        $domain = $this->settings['Email']['Domain'] ?: 'anonymize.anon';
        foreach ($stringArr as $column) {
            $setString[] =  sprintf(
                " %s = CONCAT('%s','+','%s',%s,'@','%s')",
                $column,
                $mailbox,
                $column,
                'ID',
                $domain);
        }
        return $setString;
    }
}
