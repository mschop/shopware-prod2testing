<?php

namespace Prod2Testing\Commands;

use Doctrine\DBAL\Connection;
use Shopware\Commands\ShopwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends ShopwareCommand
{
    protected $conn;
    protected $dbConfig;

    /**
     * RunCommand constructor.
     * @param $conn
     */
    public function __construct(Connection $conn, $dbConfig)
    {
        parent::__construct();
        $this->conn = $conn;
        $this->dbConfig = $dbConfig;
    }


    protected function configure()
    {
        $this->setName('prod2testing:run');

        $this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to a configuriation json file, that should be used instead of the default config.');
        $this->addOption('additionalConfig', 'a', InputOption::VALUE_OPTIONAL|InputOption::VALUE_IS_ARRAY, 'Path to a configuration json file, that should be added to the default config');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFile = __DIR__ . '/../config.json';
        $replaceConfigFile = $input->getOption('config');
        if ($replaceConfigFile) {
            $configFile = $replaceConfigFile;
        }
        if (!is_file($configFile)) {
            $output->writeln('<error>The given config file is not readable</error>');
            return 1;
        }
        if (!is_readable($configFile)) {
            $output->writeln('<error>The given config file is not readable</error>');
            return 1;
        }
        $config = json_decode(file_get_contents($configFile));

        if (!$config) {
            $output->writeln('<error>The configuration contains invalid json</error>');
        }

        foreach ($input->getOption('additionalConfig') as $additionalConfigFile) {
            if (!is_file($additionalConfigFile)) {
                $output->writeln("<error>The additional config '$additionalConfigFile' is not a file.</error>");
                return 1;
            }
            if (!is_readable($additionalConfigFile)) {
                $output->writeln("<error>The additional config '$additionalConfigFile' is not readable.</error>");
                return 1;
            }
            $additionalConfig = json_decode(file_get_contents($additionalConfigFile));
            if (!$additionalConfig) {
                $output->writeln("<error>The additional config '$additionalConfigFile' contains invalid json.</error>");
            }
            $config = array_replace_recursive($config, $additionalConfig);
        }

        // fetch schema information
        $informationSchema = $this->fetchSchema($config);


        // Check whether tables and columns defined in the config exist
        foreach ($config as $tableName => $tableConfig) {
            if (!isset($informationSchema[$tableName])) {
                $output->writeln("<warning>The table '$tableName' is configured but does not exist in db. Aborting.</warning>");
            }
            $tableSchema = $informationSchema[$tableName];
            foreach ($tableConfig as $columnName => $value) {
                if (!isset($tableSchema[$columnName])) {
                    $output->writeln("<warning>The column '$tableName.$columnName' is configured but does not exist in db. Aborting.</warning>");
                }
            }
        }

        // Do the work
        $this->conn->exec("START TRANSACTION");
        foreach ($config as $tableName => $tableConfig) {
            $output->writeln("<info>Anonymize $tableName</info>");
            $keys = $this->conn->query("SHOW KEYS FROM `$tableName` WHERE Key_name = 'PRIMARY'")->fetchAll(\PDO::FETCH_ASSOC);
            $keyNames = array_map(function($row) {
                return $row['Column_name'];
            }, $keys);
            $keyNamesString = '`' . implode('`, `', $keyNames) . '`';
            $columnNamesString = '`' . implode('`, `', array_keys((array)$tableConfig)) . '`';
            $x = 1;
            $stmt = $this->conn->query("SELECT $keyNamesString, $columnNamesString FROM `$tableName`");
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                $qb = $this->conn->createQueryBuilder();
                $qb->update($tableName);

                foreach ($tableConfig as $columnName => $value) {
                    if (empty($row[$columnName])) {
                        continue;
                    }
                    if (is_string($value)) {
                        $value = str_replace('{{x}}', $x, $value);
                    }
                    $param = ":{$columnName}_{$x}";
                    $qb->set($columnName, $param);
                    $qb->setParameter($param, $value);
                }

                foreach($keyNames as $keyName) {
                    $qb->where(
                        $qb->expr()->eq($keyName, $row[$keyName])
                    );
                }
                $qb->execute();
                $x++;
            }
        }
        $this->conn->exec("COMMIT");

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
        foreach ($config as $tableName => $tableConfig) {
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
}