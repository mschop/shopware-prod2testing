# shopware-prod2testing

If you develop new features for a shop, the best test data are those from the production system. Directly using
production databases for creating development or staging instances can cause legal and organisational problems.

This plugins runs all necessary steps, to transform a production db to a staging or testing db.

## Installation

- Download the repo
- Place it into /custom/plugins

## Run

The plugin can be executed by running the following command:

    bin/console prod2testing:run
    
## Anonymization Configuration

The plugin replaces all non empty values from the db, depending on the provided configuration. The anonymization
configuration is defined in the file `config.json`. You have two ways, to change the configuration:

### Override

```
bin/console prod2testing:run --config /path/to/your/custom-config.json
```

This replaces the anonymization configuration completely.

### Extend

```
bin/console prod2testing:run --additionalConfig /path/to/your/extension-config.json
```

This extends or overrides specific entries in the original config.

You can also declare multiple --additionalConfig options and combine it with the --config option.
```
bin/console prod2testing:run --additionalConfig /path1.json --additionalConfig /path2.json --config /base-config.json
```

## Remove Secure Flag

You can remove the secure flag from the shops, if your local installation does not have tls installed. Just use the
`--remove-secure-flag` option, and the script will uncheck the secure flag for you.

## No Remove Secret

By default, the script will try to remove secret information like passwords from the db. If you don't want it to do
that, just use the `--no-remove-secret` option.

## Resetting passwords

```
bin/console prod2testing:reset-passwords [newpassword]
```

Resets all passwords in the user table to `newpassword`.
This makes it easier to log in as different users during troubleshooting.