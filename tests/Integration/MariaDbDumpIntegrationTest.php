<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup\Tests\Integration;

use MarekSkopal\MariaDbBackup\MariaDbDump;
use mysqli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises the real dump path (proc_open -> mariadb-dump -> gzip) against a live MariaDB.
 *
 * Skipped unless a MariaDB is configured via the MARIADB_TEST_* environment variables and
 * the mariadb-dump client is on PATH, so the unit test run stays self-contained. CI provides
 * both via a service container.
 */
#[CoversClass(MariaDbDump::class)]
final class MariaDbDumpIntegrationTest extends TestCase
{
    private const string Database = 'mariadb_backup_integration';

    private string $host;

    private int $port;

    private string $user;

    private string $password;

    private string $backupFilePath;

    protected function setUp(): void
    {
        $host = getenv('MARIADB_TEST_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('MARIADB_TEST_HOST is not set; skipping MariaDB integration test.');
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
        $this->backupFilePath = sys_get_temp_dir() . '/mariadb-backup-it-' . bin2hex(random_bytes(4)) . '.sql.gz';

        $this->seedDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->backupFilePath) && file_exists($this->backupFilePath)) {
            unlink($this->backupFilePath);
        }

        if (isset($this->host)) {
            $this->connection()->query('DROP DATABASE IF EXISTS `' . self::Database . '`');
        }
    }

    public function testDumpProducesRestorableGzip(): void
    {
        $dump = new MariaDbDump(
            mariaDbHost: $this->host,
            mariaDbPort: $this->port,
            mariaDbUser: $this->user,
            mariaDbPassword: $this->password,
            mariaDbDatabase: self::Database,
            backupFilePath: $this->backupFilePath,
        );

        $dump->dump();

        self::assertFileExists($this->backupFilePath);
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($this->backupFilePath)), -4));

        $sql = gzdecode((string) file_get_contents($this->backupFilePath));
        self::assertIsString($sql);
        self::assertStringContainsString('CREATE TABLE `widgets`', $sql);
        self::assertStringContainsString('alpha', $sql);

        $dump->clean();
        self::assertFileDoesNotExist($this->backupFilePath);
    }

    public function testDumpFailsOnBadCredentials(): void
    {
        $dump = new MariaDbDump(
            mariaDbHost: $this->host,
            mariaDbPort: $this->port,
            mariaDbUser: $this->user,
            mariaDbPassword: $this->password . '-wrong',
            mariaDbDatabase: self::Database,
            backupFilePath: $this->backupFilePath,
        );

        $this->expectException(RuntimeException::class);

        $dump->dump();
    }

    private function seedDatabase(): void
    {
        $connection = $this->connection();
        $connection->query('DROP DATABASE IF EXISTS `' . self::Database . '`');
        $connection->query('CREATE DATABASE `' . self::Database . '`');
        $connection->select_db(self::Database);
        $connection->query('CREATE TABLE `widgets` (`id` INT PRIMARY KEY, `name` VARCHAR(50) NOT NULL)');
        $connection->query("INSERT INTO `widgets` (`id`, `name`) VALUES (1, 'alpha'), (2, 'beta')");
    }

    private function connection(): mysqli
    {
        return new mysqli($this->host, $this->user, $this->password, '', $this->port);
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
