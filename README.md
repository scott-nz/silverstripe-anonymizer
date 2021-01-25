# silverstripe-anonymizer
Anonymize PII from DB tables

##Example yml config
```
Member:
  Columns:
    Email:
      - Email
    String:
      - FirstName
      - Surname
  Exclude:
    FirstName:
      - "Default Admin"
    Email:
      - "scottnz@anonymizer.anon"
  Settings:
    EmailDomain: "anonymizer.anon"

```
