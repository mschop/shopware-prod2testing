# shopware-prod2testing

If you develop new features for a shop, the best test data are those from the production system. Directly using
production databases for creating development or staging instances can cause legal and organisational problems.

This plugins runs all necessary steps, to transform a production db to a staging or testing db.

## Installation

- Download the repo
- Place it into /custom/plugins

# Run

The plugin can be executed by running the following command:

    bin/console prod2testing:run