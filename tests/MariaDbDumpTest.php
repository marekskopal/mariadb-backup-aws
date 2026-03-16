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
}
