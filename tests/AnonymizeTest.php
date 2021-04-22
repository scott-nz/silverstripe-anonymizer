<?php

class AnonymizeTest extends SapphireTest
{
    protected static $fixture_file = 'anonymize_fixtures.yml';

    public function setUp()
    {
        parent::setUp();
        //ensure that the default anonymize config file is used for testing
        Anonymize::config()->__set('anonymize_config', null);
    }

    public function testAnonymizeRecords()
    {
        $anonymize = Injector::inst()->get(Anonymize::class);
        //Member ID 4 is created as the admin member, we don't want to check this record
        $members = Member::get()->byIDs([1, 2, 3]);
        foreach ($members as $member) {
            $this->assertEquals('OriginalFirstName' . $member->ID, $member->FirstName);
            $this->assertEquals('OriginalSurname' . $member->ID, $member->Surname);
            $this->assertEquals('originalmember' . $member->ID . '@email.com', $member->Email);
        }
        $excludedByEmail = Member::get()->byID(4);
        $anonymize->anonymizeRecords();

        $members = Member::get()->byIDs([1, 2, 3]);
        foreach ($members as $member) {
            $this->assertEquals('FirstName' . $member->ID, $member->FirstName);
            $this->assertEquals('Surname' . $member->ID, $member->Surname);
            $this->assertEquals('anon+email' . $member->ID . '@anonymize.me', $member->Email);
        }
    }

    public function testExcludedRecords()
    {
        $anonymize = Injector::inst()->get(Anonymize::class);
        $defaultAdmin = Member::get()->filter(['FirstName' => 'Default Admin'])->first();
        $this->assertEquals('Default Admin', $defaultAdmin->FirstName);

        $excludedByEmail = Member::get()->filter(['Email' => 'dont@anonymize.me'])->first();
        $this->assertEquals('dont@anonymize.me', $excludedByEmail->Email);

        $anonymize->anonymizeRecords();

        $defaultAdmin = Member::get()->filter(['FirstName' => 'Default Admin'])->first();
        $this->assertNotNull($defaultAdmin);
        $this->assertEquals('Default Admin', $defaultAdmin->FirstName);

        $excludedByEmail = Member::get()->filter(['Email' => 'dont@anonymize.me'])->first();
        $this->assertNotNull($excludedByEmail);
        $this->assertEquals('dont@anonymize.me', $excludedByEmail->Email);
    }

    public function testCustomConfig()
    {
        Anonymize::config()->__set('anonymize_config', 'silverstripe-anonymizer/tests/anonymize_test_config.yml');
        $anonymize = Injector::inst()->get(Anonymize::class);
        $record1 = AnonymizeTestClass::get()->first();
        $this->assertNotNull($record1);
        $this->assertEquals('originalmember1@email.com', $record1->Email);
        $this->assertEquals('OriginalStringField1', $record1->StringField);
        $this->assertEquals('OriginalNullableStringField', $record1->NullableStringField);
        $this->assertEquals('DontChangeMe', $record1->FieldThatWontBeChanged);

        $anonymize->anonymizeRecords();

        $record1 = AnonymizeTestClass::get()->first();
        $this->assertNotNull($record1);
        $this->assertEquals('Email1@anonymize.me', $record1->Email);
        $this->assertEquals('StringField1', $record1->StringField);
        $this->assertNull($record1->NullableStringField);
        $this->assertNotEquals('88888888', $record1->NumberString);
        $this->assertEquals('9', strlen($record1->NumberString));
        $this->assertEquals('DontChangeMe', $record1->FieldThatWontBeChanged);
    }
}
