<?php

global $project;
$project = 'src';

global $database;

// find the database name from the environment file
if (defined('SS_DATABASE_NAME') && SS_DATABASE_NAME) {
    $database = SS_DATABASE_NAME;
} else {
    $database = 'SS_cwp';
}

require_once('conf/ConfigureFromEnv.php');

date_default_timezone_set('Pacific/Auckland');
