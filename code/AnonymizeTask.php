<?php

/**
 * @package populate
 */
class AnonymizeTask extends BuildTask
{

    protected $title = "Anonymize Database records";

    protected $description = "Anonymize personally identifiable information from Database." .
    "Table information must be defined in a yml file detailing which columns will be anonymized.";

    public function run($request)
    {
        $anonymize = Injector::inst()->get(Anonymize::class);
        $anonymize->anonymizeRecords();
    }
}
