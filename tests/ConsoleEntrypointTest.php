<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup\Tests;

use MarekSkopal\MariaDbBackup\Tests\Support\ConsoleRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for bin/console itself.
 *
 * v0.3.1 and v1.0.0 shipped an entrypoint calling Application::add(), which
 * symfony/console 8 removed in favour of addCommand(). Every invocation died with a PHP
 * fatal error before reaching any of the classes the other tests cover, so the whole
 * suite stayed green while the tool was completely unusable. Running the real binary is
 * the only way to catch an entrypoint incompatible with the installed symfony/console.
 */
#[CoversNothing]
final class ConsoleEntrypointTest extends TestCase
{
    public function testListRegistersTheBackupCommand(): void
    {
        $result = ConsoleRunner::run(['list']);

        self::assertSame(0, $result->exitCode, $result->describe());
        self::assertStringNotContainsString('Fatal error', $result->output());
        self::assertStringContainsString('mariaDbBackup:aws', $result->stdout);
    }

    public function testBackupCommandHelpRuns(): void
    {
        $result = ConsoleRunner::run(['mariaDbBackup:aws', '--help']);

        self::assertSame(0, $result->exitCode, $result->describe());
        self::assertStringNotContainsString('Fatal error', $result->output());
        self::assertStringContainsString('--aws-bucket', $result->stdout);
        self::assertStringContainsString('--aws-endpoint', $result->stdout);
    }

    /**
     * An unconfigured run must fail with the application's own validation error rather
     * than a fatal error: that proves the entrypoint got far enough to execute the
     * command instead of dying while registering it.
     */
    public function testUnconfiguredRunFailsWithValidationErrorNotFatalError(): void
    {
        $result = ConsoleRunner::run(['mariaDbBackup:aws']);

        self::assertNotSame(0, $result->exitCode, $result->describe());
        self::assertStringNotContainsString('Fatal error', $result->output());
        self::assertStringNotContainsString('undefined method', $result->output());
        self::assertStringContainsString('must be set', $result->output());
    }
}
