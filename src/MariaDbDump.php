<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use SensitiveParameter;

final readonly class MariaDbDump
{
    private const int ChunkSize = 65536;

    public function __construct(
        private string $mariaDbHost,
        private int $mariaDbPort,
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
        if (!file_exists($this->backupFilePath)) {
            return;
        }

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

        // Restrict the backup file to the owner before any data is written: it holds a
        // full database dump and lives in a shared temp directory. Setting the umask
        // around gzopen() guarantees 0600 at creation time, avoiding a world-readable
        // window that a create-then-chmod would leave open.
        $previousUmask = umask(0o077);
        $gzip = gzopen($this->backupFilePath, 'wb9');
        umask($previousUmask);
        if ($gzip === false) {
            fclose($pipes[1]);
            proc_close($process);
            @unlink($stderrFile);

            throw new \RuntimeException(sprintf('Failed to open backup file for writing: %s', $this->backupFilePath));
        }

        $bytesWritten = 0;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], self::ChunkSize);
            if ($chunk === false) {
                break;
            }

            $bytesWritten += strlen($chunk);
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

        // mariadb-dump always emits at least a header, so zero bytes on a clean exit
        // signals a silently empty dump that we must not treat as a valid backup.
        if ($bytesWritten === 0) {
            throw new \RuntimeException('MariaDB dump produced no output');
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
            "[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\n",
            $this->quote($this->mariaDbHost),
            $this->mariaDbPort,
            $this->quote($this->mariaDbUser),
            $this->quote($this->mariaDbPassword),
        );
    }

    /** @return list<string> */
    public function createDumpCommand(string $defaultsFile): array
    {
        $command = [
            'mariadb-dump',
            '--defaults-extra-file=' . $defaultsFile,
        ];

        $databases = self::parseDatabases($this->mariaDbDatabase);

        if ($databases === []) {
            $command[] = '--all-databases';
        } elseif (count($databases) === 1) {
            $command[] = $databases[0];
        } else {
            $command[] = '--databases';
            foreach ($databases as $database) {
                $command[] = $database;
            }
        }

        return $command;
    }

    /**
     * Splits a comma-separated database list into individual names. An empty value means
     * "all databases".
     *
     * @return list<string>
     * @api Exposed for unit testing of the command builder.
     */
    public static function parseDatabases(string $databases): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $databases)),
            fn(string $database): bool => $database !== '',
        ));
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
