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
        private string $mariaDbDatabase,
        private string $backupFilePath,
    ) {
    }

    public function dump(): void
    {
        exec($this->createDumpCommand(), result_code: $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException(sprintf('MariaDB dump failed with exit code %d', $returnCode));
        }
    }

    public function clean(): void
    {
        if (!unlink($this->backupFilePath)) {
            throw new \RuntimeException(sprintf('Failed to delete temporary backup file: %s', $this->backupFilePath));
        }
    }

    private function createDumpCommand(): string
    {
        $commandParts = [
            'mariadb-dump',
            '-h',
            escapeshellarg($this->mariaDbHost),
            '-u',
            escapeshellarg($this->mariaDbUser),
            escapeshellarg('-p' . $this->mariaDbPassword),
            escapeshellarg($this->mariaDbDatabase),
            '|',
            'gzip',
            '>',
            escapeshellarg($this->backupFilePath),
        ];

        return implode(' ', $commandParts);
    }
}
