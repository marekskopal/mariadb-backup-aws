<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup\Tests;

use MarekSkopal\MariaDbBackup\MariaDbDump;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MariaDbDump::class)]
final class MariaDbDumpTest extends TestCase
{
    public function testConstruct(): void
    {
        $this->expectNotToPerformAssertions();

        $dump = $this->createDump('database');
        unset($dump);
    }

    public function testCreateDumpCommandWithSingleDatabase(): void
    {
        $dump = $this->createDump('app');

        self::assertSame(
            [
                'mariadb-dump',
                '--defaults-extra-file=/tmp/my.cnf',
                '--single-transaction',
                '--routines',
                '--events',
                '--triggers',
                'app',
            ],
            $dump->createDumpCommand('/tmp/my.cnf'),
        );
    }

    public function testCreateDumpCommandWithMultipleDatabases(): void
    {
        $dump = $this->createDump('app, reporting ,logs');

        self::assertSame(
            [
                'mariadb-dump',
                '--defaults-extra-file=/tmp/my.cnf',
                '--single-transaction',
                '--routines',
                '--events',
                '--triggers',
                '--databases',
                'app',
                'reporting',
                'logs',
            ],
            $dump->createDumpCommand('/tmp/my.cnf'),
        );
    }

    public function testCreateDumpCommandWithoutDatabaseDumpsAll(): void
    {
        $dump = $this->createDump('');

        self::assertSame(
            [
                'mariadb-dump',
                '--defaults-extra-file=/tmp/my.cnf',
                '--single-transaction',
                '--routines',
                '--events',
                '--triggers',
                '--all-databases',
            ],
            $dump->createDumpCommand('/tmp/my.cnf'),
        );
    }

    public function testCreateDumpCommandAppendsExtraOptions(): void
    {
        $dump = new MariaDbDump(
            mariaDbHost: 'db.example.com',
            mariaDbPort: 3306,
            mariaDbUser: 'backup',
            mariaDbPassword: 's3cr3t',
            mariaDbDatabase: 'app',
            backupFilePath: '/tmp/test.sql.gz',
            extraDumpOptions: ['--no-data', '--skip-lock-tables'],
        );

        self::assertSame(
            [
                'mariadb-dump',
                '--defaults-extra-file=/tmp/my.cnf',
                '--single-transaction',
                '--routines',
                '--events',
                '--triggers',
                '--no-data',
                '--skip-lock-tables',
                'app',
            ],
            $dump->createDumpCommand('/tmp/my.cnf'),
        );
    }

    public function testCreateDefaultsFileContentDoesNotLeakCredentialsToArgv(): void
    {
        $dump = $this->createDump('app');

        self::assertSame(
            "[client]\nhost=\"db.example.com\"\nport=3306\nuser=\"backup\"\npassword=\"s3cr3t\"\n",
            $dump->createDefaultsFileContent(),
        );
    }

    public function testCreateDefaultsFileContentContainsConfiguredPort(): void
    {
        $dump = $this->createDump('app', 3307);

        self::assertStringContainsString("port=3307\n", $dump->createDefaultsFileContent());
    }

    public function testCreateDefaultsFileContentEscapesSpecialCharacters(): void
    {
        $dump = new MariaDbDump(
            mariaDbHost: 'localhost',
            mariaDbPort: 3306,
            mariaDbUser: 'backup',
            mariaDbPassword: 'pa"ss#wo\\rd',
            mariaDbDatabase: 'app',
            backupFilePath: '/tmp/test.sql.gz',
        );

        self::assertStringContainsString('password="pa\\"ss#wo\\\\rd"', $dump->createDefaultsFileContent());
    }

    private function createDump(string $database, int $port = 3306): MariaDbDump
    {
        return new MariaDbDump(
            mariaDbHost: 'db.example.com',
            mariaDbPort: $port,
            mariaDbUser: 'backup',
            mariaDbPassword: 's3cr3t',
            mariaDbDatabase: $database,
            backupFilePath: '/tmp/test.sql.gz',
        );
    }
}
