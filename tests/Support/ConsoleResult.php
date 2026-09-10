<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup\Tests\Support;

/**
 * Outcome of running bin/console in a subprocess.
 *
 * @api Test-only helper; consumed by the console tests.
 */
final readonly class ConsoleResult
{
    public function __construct(public int $exitCode, public string $stdout, public string $stderr)
    {
    }

    public function output(): string
    {
        return $this->stdout . $this->stderr;
    }

    /**
     * SymfonyStyle wraps output at the terminal width, so a reported S3 key can be split
     * across lines. Collapsing whitespace lets assertions match the logical line.
     */
    public function normalizedStdout(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->stdout));
    }

    /** Full context for assertion failure messages. */
    public function describe(): string
    {
        return sprintf("exit code %d\nSTDOUT:\n%s\nSTDERR:\n%s", $this->exitCode, $this->stdout, $this->stderr);
    }
}
