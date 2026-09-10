<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup\Tests\Integration;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use DateTimeInterface;
use MarekSkopal\MariaDbBackup\AwsProvider;
use MarekSkopal\MariaDbBackup\Tests\Support\ConsoleResult;
use MarekSkopal\MariaDbBackup\Tests\Support\ConsoleRunner;
use mysqli;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * End-to-end test of the whole tool: bin/console -> mariadb-dump -> gzip -> S3 upload ->
 * rotation, against a live MariaDB and MinIO (an S3-compatible server standing in for
 * AWS S3). Everything runs through the published binary in a subprocess, so the
 * entrypoint, option/environment handling, dump, upload and rotation are all covered by
 * the same assertions a real nightly cron run would exercise.
 *
 * Skipped unless the MARIADB_TEST_* and S3_TEST_* environment variables are set; see
 * docker-compose.test.yml and .env.test for a ready-made environment.
 */
#[CoversNothing]
final class BackupWorkflowIntegrationTest extends TestCase
{
    private const string Database = 'mariadb_backup_workflow_it';

    private string $host;

    private int $port;

    private string $user;

    private string $password;

    private string $bucket;

    private string $rootPath;

    private string $tempDir;

    private S3Client $s3Client;

    /** @var list<string> */
    private array $createdUsers = [];

    protected function setUp(): void
    {
        $host = getenv('MARIADB_TEST_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('MARIADB_TEST_HOST is not set; skipping backup workflow integration test.');
        }

        $endpoint = getenv('S3_TEST_ENDPOINT');
        if ($endpoint === false || $endpoint === '') {
            self::markTestSkipped('S3_TEST_ENDPOINT is not set; skipping backup workflow integration test.');
        }

        if (!self::commandExists('mariadb-dump')) {
            self::markTestSkipped('mariadb-dump binary is not available on PATH.');
        }

        if (!extension_loaded('mysqli')) {
            self::markTestSkipped('mysqli extension is required for the integration test.');
        }

        $this->host = $host;
        $this->port = (int) self::env('MARIADB_TEST_PORT', '3306');
        $this->user = self::env('MARIADB_TEST_USER', 'root');
        $this->password = self::env('MARIADB_TEST_PASSWORD', '');
        $this->bucket = self::env('S3_TEST_BUCKET', 'mariadb-backup-test');

        // A unique prefix per test keeps parallel or repeated runs from seeing each
        // other's objects, which the rotation assertions depend on.
        $this->rootPath = 'backup-' . bin2hex(random_bytes(4));

        // The console derives its dump path from sys_get_temp_dir(), which honours
        // TMPDIR. Pointing it at an empty directory lets the test assert that both the
        // dump and the temporary credentials file are cleaned up.
        $this->tempDir = sys_get_temp_dir() . '/mariadb-backup-it-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o700);

        $this->s3Client = new S3Client(AwsProvider::createClientConfig(
            self::env('S3_TEST_KEY', 'minioadmin'),
            self::env('S3_TEST_SECRET', 'minioadmin'),
            self::env('S3_TEST_REGION', 'us-east-1'),
            $endpoint,
        ));

        $this->ensureBucketExists();
        $this->seedDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->s3Client)) {
            foreach ($this->listBackupKeys() as $key) {
                $this->s3Client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
            }
        }

        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            foreach (self::tempDirEntries($this->tempDir) as $entry) {
                @unlink($this->tempDir . '/' . $entry);
            }

            rmdir($this->tempDir);
        }

        if (!isset($this->host)) {
            return;
        }

        $connection = $this->connection();
        foreach ($this->createdUsers as $user) {
            $connection->query('DROP USER IF EXISTS `' . $user . '`@`%`');
        }

        $connection->query('DROP DATABASE IF EXISTS `' . self::Database . '`');
    }

    public function testConsoleRunUploadsRestorableEncryptedBackup(): void
    {
        $result = ConsoleRunner::run(['mariaDbBackup:aws'], $this->consoleEnvironment());

        self::assertSame(0, $result->exitCode, $result->describe());
        self::assertStringContainsString('Backup uploaded to', $result->stdout);

        $keys = $this->listBackupKeys();
        self::assertCount(1, $keys, 'Exactly one backup object is expected: ' . $result->describe());

        $key = $keys[0];
        self::assertMatchesRegularExpression(
            '#^' . preg_quote($this->rootPath, '#') . '/\d{4}-\d{2}-\d{2}/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[0-9a-f]{8}\.sql\.gz$#',
            $key,
        );
        self::assertStringContainsString($key, $result->normalizedStdout(), 'The reported key must match the stored object');

        $head = $this->s3Client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        self::assertSame('AES256', $head['ServerSideEncryption'] ?? null);

        $sql = gzdecode($this->download($key));
        self::assertIsString($sql, 'The uploaded object must be valid gzip');
        self::assertStringContainsString('CREATE TABLE `widgets`', $sql);
        self::assertStringContainsString('alpha', $sql);
        // MariaDB wraps trigger DDL in version-gated comments, so match the trigger name.
        self::assertStringContainsString('widgets_after_insert', $sql, 'Triggers are part of a complete dump');

        self::assertSame([], self::tempDirEntries($this->tempDir), 'The local dump and credentials file must be cleaned up');
    }

    /**
     * The flaw observed in v0.2.0 in production: mariadb-dump failed with "Access
     * denied", yet an empty gzip was still uploaded and counted as a backup — silently
     * rotating good backups out. A failing dump must upload nothing at all.
     */
    public function testFailedDumpUploadsNothing(): void
    {
        $environment = $this->consoleEnvironment();
        $environment['DB_PASSWORD'] = $this->password . '-wrong';

        $result = ConsoleRunner::run(['mariaDbBackup:aws'], $environment);

        self::assertNotSame(0, $result->exitCode, $result->describe());
        self::assertStringContainsString('MariaDB dump failed', $result->output());
        self::assertStringNotContainsString('Backup uploaded to', $result->stdout);

        self::assertSame([], $this->listBackupKeys(), 'A failed dump must not leave any object in the bucket');
        self::assertSame([], self::tempDirEntries($this->tempDir), 'The partial dump must be cleaned up');
    }

    public function testRotationDeletesOldestBackupsBeyondTheLimit(): void
    {
        $environment = $this->consoleEnvironment();
        $environment['AWS_MAX_BACKUPS'] = '2';

        $uploaded = [];
        foreach ([0, 1, 2] as $run) {
            if ($run > 0) {
                // S3 orders by LastModified at second granularity, so space the runs out
                // to make "oldest" unambiguous.
                sleep(1);
            }

            $result = ConsoleRunner::run(['mariaDbBackup:aws'], $environment);
            self::assertSame(0, $result->exitCode, $result->describe());

            $uploaded[] = self::extractKey($result);
        }

        $remaining = $this->listBackupKeys();

        self::assertCount(2, $remaining, 'Rotation must keep exactly AWS_MAX_BACKUPS objects');
        self::assertSame([$uploaded[1], $uploaded[2]], $remaining, 'The two newest backups must survive');
        self::assertNotContains($uploaded[0], $remaining, 'The oldest backup must be deleted');
    }

    public function testBackupWithoutDatabaseDumpsAllDatabases(): void
    {
        $environment = $this->consoleEnvironment();
        unset($environment['DB_DATABASE']);

        $result = ConsoleRunner::run(['mariaDbBackup:aws'], $environment);

        self::assertSame(0, $result->exitCode, $result->describe());

        $sql = gzdecode($this->download(self::extractKey($result)));
        self::assertIsString($sql);
        self::assertStringContainsString('CREATE DATABASE', $sql, '--all-databases includes CREATE DATABASE statements');
        self::assertStringContainsString(self::Database, $sql);
    }

    /**
     * Credentials travel through a temporary --defaults-extra-file, where values must be
     * quoted and escaped for MariaDB's option-file syntax. This password combines a
     * quote, a backslash, a `#` and a trailing space: unquoted, the client trims the
     * trailing space and authentication fails, so the test fails if the quoting in
     * MariaDbDump ever regresses. Verified against the real client rather than only
     * against the expected file content.
     */
    public function testBackupSucceedsWithSpecialCharactersInPassword(): void
    {
        $password = 'p@ss"w#rd\\ x ';
        $user = 'backup_special';
        $this->createBackupUser($user, $password);

        $environment = $this->consoleEnvironment();
        $environment['DB_USER'] = $user;
        $environment['DB_PASSWORD'] = $password;

        $result = ConsoleRunner::run(['mariaDbBackup:aws'], $environment);

        self::assertSame(0, $result->exitCode, $result->describe());
        self::assertStringNotContainsString($password, $result->output(), 'The password must never be echoed');

        $sql = gzdecode($this->download(self::extractKey($result)));
        self::assertIsString($sql);
        self::assertStringContainsString('CREATE TABLE `widgets`', $sql);
    }

    /** @return array<string, string> */
    private function consoleEnvironment(): array
    {
        return [
            'PATH' => (string) getenv('PATH'),
            'TMPDIR' => $this->tempDir,
            'DB_HOST' => $this->host,
            'DB_PORT' => (string) $this->port,
            'DB_USER' => $this->user,
            'DB_PASSWORD' => $this->password,
            'DB_DATABASE' => self::Database,
            'AWS_ACCESS_KEY' => self::env('S3_TEST_KEY', 'minioadmin'),
            'AWS_SECRET_ACCESS_KEY' => self::env('S3_TEST_SECRET', 'minioadmin'),
            'AWS_REGION' => self::env('S3_TEST_REGION', 'us-east-1'),
            'AWS_BUCKET' => $this->bucket,
            'AWS_ROOT_PATH' => $this->rootPath,
            'AWS_MAX_BACKUPS' => '30',
            'AWS_ENDPOINT' => self::env('S3_TEST_ENDPOINT', ''),
        ];
    }

    /** Reads the S3 key the console reported, which is what a cron log would show. */
    private static function extractKey(ConsoleResult $result): string
    {
        $matched = preg_match('#Backup uploaded to (\S+)#', $result->normalizedStdout(), $matches);
        self::assertSame(1, $matched, 'The console must report the uploaded key: ' . $result->describe());

        return $matches[1];
    }

    /** @return list<string> Keys under the test prefix, oldest first. */
    private function listBackupKeys(): array
    {
        $paginator = $this->s3Client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $this->rootPath . '/',
        ]);

        $objects = [];
        foreach ($paginator as $page) {
            /** @var list<array{Key: string, LastModified: DateTimeInterface}> $contents */
            $contents = $page['Contents'] ?? [];
            foreach ($contents as $object) {
                $objects[] = $object;
            }
        }

        usort($objects, fn(array $a, array $b): int => $a['LastModified'] <=> $b['LastModified']);

        return array_map(fn(array $object): string => $object['Key'], $objects);
    }

    private function download(string $key): string
    {
        $body = $this->s3Client->getObject(['Bucket' => $this->bucket, 'Key' => $key])['Body'];
        if (!$body instanceof StreamInterface) {
            throw new RuntimeException('Unexpected S3 response body for ' . $key);
        }

        return $body->getContents();
    }

    private function ensureBucketExists(): void
    {
        try {
            $this->s3Client->headBucket(['Bucket' => $this->bucket]);
        } catch (S3Exception) {
            $this->s3Client->createBucket(['Bucket' => $this->bucket]);
        }
    }

    private function createBackupUser(string $user, string $password): void
    {
        $connection = $this->connection();
        $quotedPassword = $connection->real_escape_string($password);

        $connection->query('DROP USER IF EXISTS `' . $user . '`@`%`');
        $connection->query('CREATE USER `' . $user . '`@`%` IDENTIFIED BY \'' . $quotedPassword . '\'');
        $connection->query('GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON `' . self::Database . '`.* TO `' . $user . '`@`%`');
        $connection->query('FLUSH PRIVILEGES');

        $this->createdUsers[] = $user;
    }

    private function seedDatabase(): void
    {
        $connection = $this->connection();
        $connection->query('DROP DATABASE IF EXISTS `' . self::Database . '`');
        $connection->query('CREATE DATABASE `' . self::Database . '`');
        $connection->select_db(self::Database);
        $connection->query('CREATE TABLE `widgets` (`id` INT PRIMARY KEY, `name` VARCHAR(50) NOT NULL)');
        $connection->query("INSERT INTO `widgets` (`id`, `name`) VALUES (1, 'alpha'), (2, 'beta')");
        $connection->query('CREATE TABLE `widget_audit` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(50) NOT NULL)');
        $connection->query(
            'CREATE TRIGGER `widgets_after_insert` AFTER INSERT ON `widgets` FOR EACH ROW '
            . 'INSERT INTO `widget_audit` (`name`) VALUES (NEW.`name`)',
        );
    }

    private function connection(): mysqli
    {
        return new mysqli($this->host, $this->user, $this->password, '', $this->port);
    }

    /** @return list<string> */
    private static function tempDirEntries(string $directory): array
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return [];
        }

        return array_values(array_filter($entries, fn(string $entry): bool => $entry !== '.' && $entry !== '..'));
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    private static function commandExists(string $command): bool
    {
        exec('command -v ' . escapeshellarg($command), $output, $code);

        return $code === 0;
    }
}
