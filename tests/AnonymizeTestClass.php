<?php

namespace ScottNZ\Anonymize\Tests;

use SilverStripe\ORM\DataObject;

class AnonymizeTestClass extends DataObject
{
    private static $table_name = 'AnonymizeTestClass';

    private static $db = [
        'StringField' => 'Varchar(50)',
        'Email' => 'Varchar(50)',
        'NullableStringField' => 'Varchar(50)',
        'NumberString' => 'Varchar(50)',
        'FieldThatWontBeChanged' => 'Varchar(50)'
    ];
}
