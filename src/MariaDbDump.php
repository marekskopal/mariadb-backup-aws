<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use SensitiveParameter;

final readonly class MariaDbDump
{
    public function __construct(
        private string $mariaDbHost,
        private string $mariaDbUser,
        #[SensitiveParameter] private string $mariaDbPassword,
        private string $maraDbDatabase,
        private string $backupFileName,
    ) {
    }

    public function dump(): void
    {
        exec($this->createDumpCommand());
    }

    public function clean(): void
    {
        unlink($this->getBackupFilePath());
    }

    private function createDumpCommand(): string
    {
        $commandParts = [
            'mariadb-dump',
            '-h',
            escapeshellarg($this->mariaDbHost),
            '-u',
            escapeshellarg($this->mariaDbUser),
            '-p' . $this->mariaDbPassword,
            escapeshellarg($this->maraDbDatabase),
            '|',
            'gzip',
            '>',
            escapeshellarg($this->getBackupFilePath()),
        ];

        return implode(' ', $commandParts);
    }

    private function getBackupFilePath(): string
    {
        return sys_get_temp_dir() . '/' . $this->backupFileName;
    }
}
