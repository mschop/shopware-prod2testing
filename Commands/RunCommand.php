<?php

namespace Prod2Testing\Commands;

use Doctrine\DBAL\Connection;
use Shopware\Commands\ShopwareCommand;
use Shopware\Components\ConfigWriter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

require_once(__DIR__ . '/../vendor/autoload.php');

class RunCommand extends ShopwareCommand
{
    const OPTION_REMOVE_TLS = 'remove-secure-flag';
    const OPTION_NO_SECRET_REMOVE = 'no-remove-secret';

    protected $conn;
    protected $dbConfig;
    protected $configWriter;
    protected $faker;

    /**
     * RunCommand constructor.
     * @param Connection $conn
     * @param $dbConfig
     * @param ConfigWriter $configWriter
     */
    public function __construct(Connection $conn, array $dbConfig, ConfigWriter $configWriter)
    {
        parent::__construct();
        $this->conn = $conn;
        $this->dbConfig = $dbConfig;
        $this->configWriter = $configWriter;
    }


    protected function configure()
    {
        $this->setName('prod2testing:run');

        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_OPTIONAL,
            'Path to a configuriation json file, that should be used instead of the default config.'
        );
        $this->addOption(
            'additionalConfig',
            'a',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Path to a configuration json file, that should be added to the default config'
        );
        $this->addOption(
            self::OPTION_REMOVE_TLS,
            null,
            InputOption::VALUE_OPTIONAL,
            'You can disable tls, if you dev maschine is not created with tls support'
        );
        $this->addOption(
            self::OPTION_NO_SECRET_REMOVE,
            null,
            InputOption::VALUE_OPTIONAL,
            'Disable wiping of secrets (e.g. smtp, payment methods etc.)'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /*
         * start transaction
         */
        $output->writeln('');
        $output->writeln('<info>- start transaction</info>');
        $this->conn->exec('START TRANSACTION');

        /*
         * do tasks
         */
        $this->anonymizeData($input, $output);
        $this->removeSecrets($input, $output);
        $this->removeTLSFromShops($input, $output);

        /*
         * submit Changes
         */
        $output->writeln('<info>- commit changes</info>');
        $this->conn->exec('COMMIT');
        $output->writeln('');
        $output->writeln('<info>Success!</info>');
        $output->writeln('');

        return 0;
    }

    /**
     * @param $config
     * @return array[]
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function fetchSchema($config)
    {
        $allTables = [];
        foreach ($config->anonymize as $tableName => $tableConfig) {
            $allTables[] = $tableName;
        }
        $stmt = $this->conn->executeQuery("
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                COLUMN_DEFAULT,
                IS_NULLABLE,
                DATA_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME IN (?)
        ", [
            $this->dbConfig['dbname'],
            $allTables,
        ], [
            \PDO::PARAM_STR,
            Connection::PARAM_STR_ARRAY,
        ]);
        $result = [];

        // group schema entries by table name and index schema entries by column name
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $tableName = $row['TABLE_NAME'];
            $colName = $row['COLUMN_NAME'];
            if (isset($result[$tableName])) {
                $result[$tableName][$colName] = $row;
            } else {
                $result[$tableName] = [$colName => $row];
            }
        }
        return $result;
    }

    protected function anonymizeData(InputInterface $input, OutputInterface $output)
    {
        /*
         * Read base config
         */
        $configFile = __DIR__ . '/../config.json';
        $replaceConfigFile = $input->getOption('config');
        if ($replaceConfigFile) {
            $configFile = $replaceConfigFile;
        }
        if (!is_file($configFile)) {
            throw new \Exception("Anonymization configuration file does not exist.");
        }
        if (!is_readable($configFile)) {
            $output->writeln('<error>The given config file is not readable</error>');
            throw new \Exception("Anonymization configuration file is not readable");
        }

        $config = json_decode(file_get_contents($configFile));

        if (!$config) {
            $output->writeln('<error>The configuration contains invalid json</error>');
        }

        /*
         * Read additional config
         */
        foreach ($input->getOption('additionalConfig') as $additionalConfigFile) {
            if (!is_file($additionalConfigFile)) {
                throw new \Exception("The additional config '$additionalConfigFile' is not a file");
            }
            if (!is_readable($additionalConfigFile)) {
                throw new \Exception("The additional config '$additionalConfigFile' is not readable");
            }

            $additionalConfig = json_decode(file_get_contents($additionalConfigFile));
            if (!$additionalConfig) {
                throw new \Exception("The additional config '$additionalConfigFile' contains invalid json");
            }
            $config = array_replace_recursive($config, $additionalConfig);
        }

        $lang = $config->fakerLanguageProvider;
        $this->faker = \Faker\Factory::create($lang);
        // Add provider at_AT\Payment because de_DE has no vat generator
        $paymentClass = "\Faker\Provider\at_AT\Payment";
        $this->faker->addProvider(new $paymentClass($this->faker));

        // fetch schema information
        $informationSchema = $this->fetchSchema($config);

        $output->writeln('<info>- anonymize:</info>');

        // run anonymization
        foreach ($config->anonymize as $tableName => $tableConfig) {

            if (!isset($informationSchema[$tableName])) {
                $output->writeln("<warning>\t\tThe table '$tableName' is configured but does not exist in db. Continuing with next table.</warning>");
                continue;
            }

            $output->writeln("<info>\t- $tableName</info>");
            $keys = $this->conn->query("SHOW KEYS FROM `$tableName` WHERE Key_name = 'PRIMARY'")->fetchAll(\PDO::FETCH_ASSOC);
            $keyNames = array_map(function ($row) {
                return $row['Column_name'];
            }, $keys);
            $keyNamesString = '`' . implode('`, `', $keyNames) . '`';
            $columnNamesString = '`' . implode('`, `', array_keys((array)$tableConfig)) . '`';
            $x = 1;
            $stmt = $this->conn->query("SELECT $keyNamesString, $columnNamesString FROM `$tableName`");
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                $qb = $this->conn->createQueryBuilder();
                $qb->update($tableName);

                $hasUpdate = false;
                foreach ($tableConfig as $columnName => $value) {
                    if (!isset($informationSchema[$tableName][$columnName])) {
                        $output->writeln("<warning>\t\tThe column '$tableName.$columnName' does not exist in db. Continuing with next column.</warning>");
                        continue;
                    }

                    if (empty($row[$columnName])) {
                        continue;
                    }
                    $hasUpdate = true;
                    if (is_object($value)) {
                        $value = $this->fakeValue($value);
                    } elseif (is_string($value)) {
                        $value = str_replace('{{x}}', $x, $value);
                    }
                    $param = ":{$columnName}_{$x}";
                    $qb->set($columnName, $param);
                    $qb->setParameter($param, $value);
                }
                if (!$hasUpdate) continue;

                foreach ($keyNames as $keyName) {
                    $qb->where(
                        $qb->expr()->eq($keyName, $row[$keyName])
                    );
                }
                $qb->execute();
                $x++;
            }
        }

        $output->writeln('<info>- truncate:</info>');
        foreach ($config->truncate as $table) {
            $output->writeln("<info>\t- $table</info>");
            $this->conn->exec("DELETE FROM `$table`");
        }
    }

    /**
     * Wipes secrets from database (e.g. smtp password)
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function removeSecrets(InputInterface $input, OutputInterface $output)
    {
        $noSecretRemove = $input->getOption(self::OPTION_NO_SECRET_REMOVE);
        if ($noSecretRemove) return;

        $output->writeln('<info>- remove secrets</info>');
        $output->writeln("<info>\t- set mail method to php mail & remove smtp password</info>");

        $stmt = $this->conn->query("SELECT id FROM s_core_shops");
        while(($shopId = $stmt->fetchColumn())) {
            $this->configWriter->save('mailer_mailer', 'mail', null, $shopId);
            $this->configWriter->save('mailer_password', '', null, $shopId);
        }
    }

    /**
     * Remove TLS flag from shops for enabling local development
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function removeTLSFromShops(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>- deactivate tls for all shops</info>');
        $removeTLS = $input->getOption(self::OPTION_REMOVE_TLS);
        if ($removeTLS) $this->conn->exec("UPDATE s_core_shops SET secure = 0");
    }

    protected function fakeValue($definition)
    {
        $type = $definition->type;
        $params = $definition->params ?? [];
        $unique = $definition->unique ?? false;
        $faker = $unique ? $this->faker->unique() : $this->faker;
        return call_user_func_array([$faker, $type], $params);
    }
}