# silverstripe-anonymizer
The main purpose of this module is to enable users to quickly remove any personally identifiable information (PII) from a Silverstripe database.

Each website is different and the location and amount of PII will vary from site to site, with this in mind the module is set up to be highly customizable by using a yml configuration file.

## Config
To use a custom yml configuration on your site add the following config to your sites yml configurations:

````
ScottNZ\Anonymize\Objects\Anonymize:
  anonymize_config:
    - [PathToFile.yml]
````

## What can be anonymized?
Any database field can be configured to be anonymized. There are three default column types that can easily be configured

### String Fields
All string fields specified for a Data Object will be anonymized so that all values in the defined column [ColumnName] will be renamed to [ColumnName][ID].

Example:
A table with the following row

|ID|FirstName|Surname|
|---|---|---|
|1|John|Doe|

would become

|ID|FirstName|Surname|
|---|---|---|
|1|FirstName1|Surname1|

### Null Fields
All defined Null fields will have their data set to NULL

### Email Fields
All defined Email fields will be anonymized to [ColumnName][ID]@[Configured Domain]

The domain used for anonymizing email fields can be set within the `Settings` section of the yml file. 

If a domain is not set, ther is a default fallback to `anonymize.anon`

Example:

If the Domain config has been set to `yourdomain.com` in the yml file.

|ID|Email|
|---|---|
|1|john.doe@emailaddress.com|

would become

|ID|Email|
|---|---|
|1|Email1@yourdomain.com|

## Excluding records
There will be some database records that you do not want to be altered.

An obvious example of this is the `Members` table. 
If you were to anonymize all PII from this table then you would quickly find that you can't log in to your account as the email address for your admin account has changed.

To ensure that this doesn't happen, you can define settings to exclude certain records based on the values in a column. 
The default config file in this repo provides an example of how to exclude Member records from being anonymized.

````
Exclude:
  FirstName:
    - "Default Admin"
  Email:
    - "dont@anonymize.me"
````
This config snippet will ensure that any member with the FirstName 'Default Admin', or the Email 'dont@anonymize.me' will not be anonymized when the task is run.

For every column defined in the `Exclude` config, you can define multiple values that should be excluded from being anonymized. 

## Custom field functions
Some times the default functionality will not be enough to anonymize the data while keeping it usable in a dev environment. This is where the ability to use custom functions comes in.

Custom field functions can be used for more advanced anonymization or for more customized exclusions.

This module includes some custom functions that are ready to use out of the box. If you are wanting to create your own custom functions then you will need to extend the `Anonymize` class in your own code base and add these additional custom functions.

Any custom function declarations in the yml file will override standard column type definitions.

NOTE: If a custom function is defined in the yml config, but the function can not be located in the code base, an exception will be thrown before any data manipulation has occurred.

### singleEmailAddress
This custom function sets all email addresses to a single mailbox.
The function uses the + character in the email address so that testers can still differentiate between Member accounts.

This function will change all values in the chosen Email field to the following format
[Mailbox]+[ColumnName][ID]@[Domain]

`Mailbox` variable must be set when the custom function is defined.
`ColumnName` is the name of the column that this function is being called on
`ID` ID of the record
`Domain` as set in the main `Settings` section of the config yml

Example config: 
````
DataObjects:
  SilverStripe\Security\Member:
    CustomFieldFunctions:
      Column:
        Email:
          FunctionName: "singleEmailAddress"
          Variables:
            Mailbox: "anon"
Settings:
  EmailField:
    Domain: "anonymize.me"
````

|ID|Email|
|---|---|
|1|john.doe@emailaddress.com|
|2|jane.doe@emailaddress.com|

would become

|ID|Email|
|---|---|
|1|anon+Email1@anonymize.me|
|2|anon+Email2@anonymize.me|

### generateRandomNumberString
This function is aimed at anonymizing phone numbers or other PII such as an IRD number
The function will generate a random string of numbers with a defined character length.

The character length can be configured in the yml file however if it is not defined the default setting of `9` characters will be used

Example config:
````
DataObjects:
  SilverStripe\Security\Member:
    CustomFieldFunctions:
      Column:
        PhoneNumber:
          FunctionName: "generateRandomNumberString"
          Variables:
            Length: 7
````
This will result in each `PhoneNumber` record being set to a random number between `1000000` and `9999999`

### excludeAdministrators
Anonymizing data would be pointless if no one could access the site once the task has been run.

That is where this custom function can come in handy as it will ensure that any site Administrators do not have their data altered.

This function is specifically designed to be called against the `ID` field of the `Members` object.
No change will be made to the field if the defined field is not `ID`

Example config:
````
DataObjects:
  SilverStripe\Security\Member:
    CustomFieldFunctions:
      Exclude:
        ID:
          FunctionName: "excludeAdministrators"
````

## CustomDeleteFunction
For every DataObject it is possible to define a single custom delete function.

### This is destructive behaviour and should be avoided where possible. 

The module allows for data to be deleted however the entire delete functionality must be contained within custom site code.

No data will be deleted by this module without extending the `Anonymize` class and defining a delete function in the yml file.
