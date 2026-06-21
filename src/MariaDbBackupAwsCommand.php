<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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

        $this->addOption(self::OptionHost, 'H', InputOption::VALUE_REQUIRED, 'MariaDB host');
        $this->addOption(self::OptionUser, 'u', InputOption::VALUE_REQUIRED, 'MariaDB user');
        $this->addOption(self::OptionPassword, 'p', InputOption::VALUE_REQUIRED, 'MariaDB password');
        $this->addOption(
            self::OptionDatabase,
            'd',
            InputOption::VALUE_REQUIRED,
            'MariaDB database (comma-separated for several; all databases if omitted)',
        );

        $this->addOption(self::OptionAwsAccessKey, 'a', InputOption::VALUE_REQUIRED, 'AWS access key');
        $this->addOption(self::OptionAwsSecretAccessKey, 's', InputOption::VALUE_REQUIRED, 'AWS secret access key');
        $this->addOption(self::OptionAwsRegion, 'r', InputOption::VALUE_REQUIRED, 'AWS region');
        $this->addOption(self::OptionAwsBucket, 'b', InputOption::VALUE_REQUIRED, 'AWS bucket name');
        $this->addOption(self::OptionAwsRootPath, 'o', InputOption::VALUE_REQUIRED, 'AWS root path');
        $this->addOption(self::OptionAwsMaxBackups, 'm', InputOption::VALUE_REQUIRED, 'AWS max. backups');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // A random suffix keeps two runs in the same second from overwriting each other
        // (locally and in the S3 key, which is derived from this filename).
        $backupFileName = sprintf('%s_%s.sql.gz', date('Y-m-d_H-i-s'), bin2hex(random_bytes(4)));
        $backupFilePath = sys_get_temp_dir() . '/' . $backupFileName;

        $mariaDbDump = new MariaDbDump(
            mariaDbHost: $this->getOptionOrEnv($input, self::OptionHost, 'DB_HOST'),
            mariaDbUser: $this->getOptionOrEnv($input, self::OptionUser, 'DB_USER'),
            mariaDbPassword: $this->getOptionOrEnv($input, self::OptionPassword, 'DB_PASSWORD'),
            mariaDbDatabase: $this->getOptionOrEnv($input, self::OptionDatabase, 'DB_DATABASE', ''),
            backupFilePath: $backupFilePath,
        );
        $awsProvider = new AwsProvider(
            awsKey: $this->getOptionOrEnv($input, self::OptionAwsAccessKey, 'AWS_ACCESS_KEY'),
            awsSecret: $this->getOptionOrEnv($input, self::OptionAwsSecretAccessKey, 'AWS_SECRET_ACCESS_KEY'),
            awsRegion: $this->getOptionOrEnv($input, self::OptionAwsRegion, 'AWS_REGION'),
            awsBucket: $this->getOptionOrEnv($input, self::OptionAwsBucket, 'AWS_BUCKET'),
            rootPath: $this->getOptionOrEnv($input, self::OptionAwsRootPath, 'AWS_ROOT_PATH', 'backup'),
            maxBackups: (int) $this->getOptionOrEnv($input, self::OptionAwsMaxBackups, 'AWS_MAX_BACKUPS', '30'),
        );

        $io = new SymfonyStyle($input, $output);

        try {
            $io->writeln('Dumping MariaDB database...');
            $mariaDbDump->dump();

            $io->writeln('Uploading backup to S3...');
            $key = $awsProvider->upload($backupFilePath);
        } finally {
            // Always remove the local dump, even if the dump or upload failed, so the
            // sensitive backup is never left behind in the temp directory.
            $mariaDbDump->clean();
        }

        $io->success(sprintf('Backup uploaded to %s', $key));

        return self::SUCCESS;
    }

    private function getOptionOrEnv(InputInterface $input, string $optionName, string $envName, ?string $default = null): string
    {
        /** @var string|null $option */
        $option = $input->getOption($optionName);
        if ($option !== null && $option !== '') {
            return $option;
        }

        $env = getenv($envName);
        if ($env !== false && $env !== '') {
            return $env;
        }

        if ($default !== null) {
            return $default;
        }

        throw new \InvalidArgumentException(sprintf('Option %s or environment variable %s must be set', $optionName, $envName));
    }
}
