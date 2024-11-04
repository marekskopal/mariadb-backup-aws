<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MariaDbBackupAwsCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('mariaDbBackup:aws');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backupFilePath = getenv('DB_BACKUP_PATH') . '/' . date('Y-m-d_H-i-s') . '.sql.gz';

        $mariaDbDump = new MariaDbDump(
            (string) getenv('DB_HOST'),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD'),
            (string) getenv('DB_DATABASE'),
            $backupFilePath,
        );
        $mariaDbDump->dump();

        $awsProvider = new AwsProvider(
            (string) getenv('AWS_ACCESS_KEY'),
            (string) getenv('AWS_SECRET_ACCESS_KEY'),
            (string) getenv('AWS_REGION'),
            (string) getenv('AWS_BUCKET'),
        );
        $awsProvider->upload($backupFilePath);

        $mariaDbDump->clean();

        return self::SUCCESS;
    }
}
