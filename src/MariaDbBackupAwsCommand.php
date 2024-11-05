<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MariaDbBackupAwsCommand extends Command
{
    private const string CommandName = 'mariaDbBackup:aws';

    private const string OptionHost = 'host';
    private const string OptionUser = 'user';
    private const string OptionPassword = 'password';
    private const string OptionDatabase = 'database';

    private const string OptionAwsAccessKey = 'aws-access-key';
    private const string OptionAwsSecretAccessKey = 'aws-secret';
    private const string OptionAwsRegion = 'aws-region';
    private const string OptionAwsBucket = 'aws-bucket';
    private const string OptionAwsRootPath = 'aws-root-path';
    private const string OptionAwsMaxBackups = 'aws-max-backups';

    protected function configure(): void
    {
        $this->setName(self::CommandName);

        $this->setDescription('Backup MariaDB to AWS S3');

        $this->addOption(self::OptionHost, 'H', InputArgument::OPTIONAL, 'MariaDB host');
        $this->addOption(self::OptionUser, 'u', InputArgument::OPTIONAL, 'MariaDB user');
        $this->addOption(self::OptionPassword, 'p', InputArgument::OPTIONAL, 'MariaDB password');
        $this->addOption(self::OptionDatabase, 'd', InputArgument::OPTIONAL, 'MariaDB database');

        $this->addOption(self::OptionAwsAccessKey, 'a', InputArgument::OPTIONAL, 'AWS access key');
        $this->addOption(self::OptionAwsSecretAccessKey, 's', InputArgument::OPTIONAL, 'AWS secret access key');
        $this->addOption(self::OptionAwsRegion, 'r', InputArgument::OPTIONAL, 'AWS region');
        $this->addOption(self::OptionAwsBucket, 'b', InputArgument::OPTIONAL, 'AWS bucket name');
        $this->addOption(self::OptionAwsRootPath, 'o', InputArgument::OPTIONAL, 'AWS root path');
        $this->addOption(self::OptionAwsMaxBackups, 'm', InputArgument::OPTIONAL, 'AWS max. backups');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backupFilePath = sys_get_temp_dir() . '/' . date('Y-m-d_H-i-s') . '.sql.gz';

        $mariaDbDump = new MariaDbDump(
            mariaDbHost: $this->getOptionOrEnv($input, self::OptionHost, 'DB_HOST'),
            mariaDbUser: $this->getOptionOrEnv($input, self::OptionUser, 'DB_USER'),
            mariaDbPassword: $this->getOptionOrEnv($input, self::OptionPassword, 'DB_PASSWORD'),
            maraDbDatabase: $this->getOptionOrEnv($input, self::OptionDatabase, 'DB_DATABASE'),
            backupFilePath: $backupFilePath,
        );
        $mariaDbDump->dump();

        $awsProvider = new AwsProvider(
            awsKey: $this->getOptionOrEnv($input, self::OptionAwsAccessKey, 'AWS_ACCESS_KEY'),
            awsSecret: $this->getOptionOrEnv($input, self::OptionAwsSecretAccessKey, 'AWS_SECRET_ACCESS_KEY'),
            awsRegion: $this->getOptionOrEnv($input, self::OptionAwsRegion, 'AWS_REGION'),
            awsBucket: $this->getOptionOrEnv($input, self::OptionAwsBucket, 'AWS_BUCKET'),
            rootPath: $this->getOptionOrEnv($input, self::OptionAwsRootPath, 'AWS_ROOT_PATH', 'backup'),
            maxBackups: (int) $this->getOptionOrEnv($input, self::OptionAwsMaxBackups, 'AWS_MAX_BACKUPS', '30'),
        );
        $awsProvider->upload($backupFilePath);

        $mariaDbDump->clean();

        return self::SUCCESS;
    }

    private function getOptionOrEnv(InputInterface $input, string $optionName, string $envName, ?string $default = null): string
    {
        /** @var string|null $option */
        $option = $input->getOption($optionName);
        if ($option !== null) {
            return $option;
        }

        $env = getenv($envName);
        if ($env !== false) {
            return $env;
        }

        if ($default !== null) {
            return $default;
        }

        throw new \InvalidArgumentException(sprintf('Option %s or environment variable %s must be set', $optionName, $envName));
    }
}
