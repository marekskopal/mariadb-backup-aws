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

        $dump = new MariaDbDump(
            mariaDbHost: 'localhost',
            mariaDbUser: 'user',
            mariaDbPassword: 'password',
            mariaDbDatabase: 'database',
            backupFilePath: '/tmp/test.sql.gz',
        );
        unset($dump);
    }

    public function testCreateDumpCommandWithSingleDatabase(): void
    {
        $dump = $this->createDump('app');

        self::assertSame(
            ['mariadb-dump', '--defaults-extra-file=/tmp/my.cnf', 'app'],
            $dump->createDumpCommand('/tmp/my.cnf'),
        );
    }

    public function testCreateDumpCommandWithMultipleDatabases(): void
    {
        $dump = $this->createDump('app, reporting ,logs');

        self::assertSame(
            ['mariadb-dump', '--defaults-extra-file=/tmp/my.cnf', '--databases', 'app', 'reporting', 'logs'],
            $dump->createDumpCommand('/tmp/my.cnf'),
        );
    }

    public function testCreateDumpCommandWithoutDatabaseDumpsAll(): void
    {
        $dump = $this->createDump('');

        self::assertSame(
            ['mariadb-dump', '--defaults-extra-file=/tmp/my.cnf', '--all-databases'],
            $dump->createDumpCommand('/tmp/my.cnf'),
        );
    }

    private function createDump(string $database): MariaDbDump
    {
        return new MariaDbDump(
            mariaDbHost: 'db.example.com',
            mariaDbUser: 'backup',
            mariaDbPassword: 's3cr3t',
            mariaDbDatabase: $database,
            backupFilePath: '/tmp/test.sql.gz',
        );
    }

    public function testCreateDefaultsFileContentDoesNotLeakCredentialsToArgv(): void
    {
        $dump = new MariaDbDump(
            mariaDbHost: 'db.example.com',
            mariaDbUser: 'backup',
            mariaDbPassword: 's3cr3t',
            mariaDbDatabase: 'app',
            backupFilePath: '/tmp/test.sql.gz',
        );

        self::assertSame(
            "[client]\nhost=\"db.example.com\"\nuser=\"backup\"\npassword=\"s3cr3t\"\n",
            $dump->createDefaultsFileContent(),
        );
    }

    public function testCreateDefaultsFileContentEscapesSpecialCharacters(): void
    {
        $dump = new MariaDbDump(
            mariaDbHost: 'localhost',
            mariaDbUser: 'backup',
            mariaDbPassword: 'pa"ss#wo\\rd',
            mariaDbDatabase: 'app',
            backupFilePath: '/tmp/test.sql.gz',
        );

        self::assertStringContainsString('password="pa\\"ss#wo\\\\rd"', $dump->createDefaultsFileContent());
    }
}
