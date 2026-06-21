<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use SensitiveParameter;

final readonly class MariaDbDump
{
    private const int ChunkSize = 65536;

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
        $this->runDump($this->createDumpCommand());
    }

    public function clean(): void
    {
        if (!unlink($this->backupFilePath)) {
            throw new \RuntimeException(sprintf('Failed to delete temporary backup file: %s', $this->backupFilePath));
        }
    }

    /**
     * Runs mariadb-dump without a shell (array form bypasses /bin/sh) and streams its
     * stdout through gzip into the backup file. Running without a pipeline guarantees
     * the exit code we read is mariadb-dump's own, not gzip's — a shell pipeline would
     * mask dump failures behind gzip's success and silently produce an empty backup.
     *
     * @param list<string> $command
     */
    private function runDump(array $command): void
    {
        $stderrFile = tempnam(sys_get_temp_dir(), 'mariadb-dump-err-');
        if ($stderrFile === false) {
            throw new \RuntimeException('Failed to create temporary file for dump error output');
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($stderrFile);

            throw new \RuntimeException('Failed to start mariadb-dump process');
        }

        $gzip = gzopen($this->backupFilePath, 'wb9');
        if ($gzip === false) {
            fclose($pipes[1]);
            proc_close($process);
            @unlink($stderrFile);

            throw new \RuntimeException(sprintf('Failed to open backup file for writing: %s', $this->backupFilePath));
        }

        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], self::ChunkSize);
            if ($chunk === false) {
                break;
            }

            gzwrite($gzip, $chunk);
        }

        gzclose($gzip);
        fclose($pipes[1]);

        $returnCode = proc_close($process);

        $stderr = (string) file_get_contents($stderrFile);
        @unlink($stderrFile);

        if ($returnCode !== 0) {
            throw new \RuntimeException(
                sprintf('MariaDB dump failed with exit code %d: %s', $returnCode, trim($stderr)),
            );
        }
    }

    /** @return list<string> */
    public function createDumpCommand(): array
    {
        return [
            'mariadb-dump',
            '-h',
            $this->mariaDbHost,
            '-u',
            $this->mariaDbUser,
            '-p' . $this->mariaDbPassword,
            $this->mariaDbDatabase,
        ];
    }
}
