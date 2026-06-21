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
        $defaultsFile = $this->createDefaultsFile();

        try {
            $this->runDump($this->createDumpCommand($defaultsFile));
        } finally {
            @unlink($defaultsFile);
        }
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

    /**
     * Writes connection credentials to a temporary option file instead of passing them
     * as command-line arguments, where they would be visible to any local user via the
     * process list (e.g. `ps aux`).
     */
    private function createDefaultsFile(): string
    {
        $defaultsFile = tempnam(sys_get_temp_dir(), 'mariadb-dump-cnf-');
        if ($defaultsFile === false) {
            throw new \RuntimeException('Failed to create temporary credentials file');
        }

        if (file_put_contents($defaultsFile, $this->createDefaultsFileContent()) === false) {
            @unlink($defaultsFile);

            throw new \RuntimeException('Failed to write temporary credentials file');
        }

        return $defaultsFile;
    }

    public function createDefaultsFileContent(): string
    {
        return sprintf(
            "[client]\nhost=%s\nuser=%s\npassword=%s\n",
            $this->quote($this->mariaDbHost),
            $this->quote($this->mariaDbUser),
            $this->quote($this->mariaDbPassword),
        );
    }

    /** @return list<string> */
    public function createDumpCommand(string $defaultsFile): array
    {
        return [
            'mariadb-dump',
            '--defaults-extra-file=' . $defaultsFile,
            $this->mariaDbDatabase,
        ];
    }

    /**
     * Quotes a value for a MariaDB option file: wrap in double quotes and escape
     * backslashes and double quotes so values with spaces or special characters
     * (e.g. `#`, which would otherwise start a comment) are read verbatim.
     */
    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
