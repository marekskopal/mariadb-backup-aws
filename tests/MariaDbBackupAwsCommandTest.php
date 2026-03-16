<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup\Tests;

use MarekSkopal\MariaDbBackup\MariaDbBackupAwsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MariaDbBackupAwsCommand::class)]
final class MariaDbBackupAwsCommandTest extends TestCase
{
    public function testCommandName(): void
    {
        $command = new MariaDbBackupAwsCommand();
        self::assertSame('mariaDbBackup:aws', $command->getName());
    }

    public function testCommandDescription(): void
    {
        $command = new MariaDbBackupAwsCommand();
        self::assertSame('Backup MariaDB to AWS S3', $command->getDescription());
    }

    public function testCommandOptions(): void
    {
        $command = new MariaDbBackupAwsCommand();
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('host'));
        self::assertTrue($definition->hasOption('user'));
        self::assertTrue($definition->hasOption('password'));
        self::assertTrue($definition->hasOption('database'));
        self::assertTrue($definition->hasOption('aws-access-key'));
        self::assertTrue($definition->hasOption('aws-secret'));
        self::assertTrue($definition->hasOption('aws-region'));
        self::assertTrue($definition->hasOption('aws-bucket'));
        self::assertTrue($definition->hasOption('aws-root-path'));
        self::assertTrue($definition->hasOption('aws-max-backups'));
    }
}
