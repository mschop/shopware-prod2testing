<?php


namespace Prod2Testing\Commands;


use Doctrine\DBAL\Connection;
use Shopware\Commands\ShopwareCommand;
use Shopware\Components\Password\Manager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResetPasswordsCommand extends ShopwareCommand
{
    /**
     * @var Connection
     */
    private $connection;
    /**
     * @var array
     */
    private $dbConfig;

    public function __construct(Connection $connection, array $dbConfig)
    {
        parent::__construct('prod2testing:reset-passwords');

        $this->connection = $connection;
        $this->dbConfig = $dbConfig;
    }

    public function configure()
    {
        $this->setDescription("Reset all customer passwords to a single password.");

        $this->addArgument('password', InputArgument::REQUIRED, 'The password to set for all customer accounts.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         * @var Manager
         */
        $passwordManager = Shopware()->PasswordEncoder();
        $encoderName = $passwordManager->getDefaultPasswordEncoderName();

        $encrypted = $passwordManager->encodePassword(
            $input->getArgument('password'),
            $encoderName
        );

        $this->connection->executeUpdate(
            'update s_user set password = ?, encoder = ?',
            [$encrypted, $encoderName]
        );
    }

}