<?php

class AnonymizeTestClass extends DataObject
{
    private static $db = [
        'StringField' => 'Varchar(50)',
        'Email' => 'Varchar(50)',
        'NullableStringField' => 'Varchar(50)',
        'NumberString' => 'Varchar(50)',
        'FieldThatWontBeChanged' => 'Varchar(50)'
    ];
}