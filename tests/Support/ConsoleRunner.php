<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup\Tests\Support;

use RuntimeException;
use const PHP_BINARY;

/**
 * Runs the real bin/console binary in a subprocess.
 *
 * Tests that exercise the entrypoint have to spawn it: an in-process test constructs the
 * command class directly and therefore never touches bin/console, which is exactly how a
 * fatal error in the entrypoint shipped unnoticed in v0.3.1 and v1.0.0.
 *
 * @api Test-only helper; consumed by the console tests.
 */
final readonly class ConsoleRunner
{
    /**
     * The environment is replaced rather than inherited, so the host's own database and
     * AWS variables cannot influence the result.
     *
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    public static function run(array $arguments, array $environment = []): ConsoleResult
    {
        $projectDir = dirname(__DIR__, 2);

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, $projectDir . '/bin/console', ...$arguments],
            $descriptors,
            $pipes,
            $projectDir,
            ['PATH' => (string) getenv('PATH'), ...$environment],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start bin/console');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new ConsoleResult(proc_close($process), $stdout, $stderr);
    }
}
