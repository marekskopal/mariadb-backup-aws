<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use MarekSkopal\MariaDbBackup\AwsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AwsProvider::class)]
final class AwsProviderTest extends TestCase
{
    public function testConstructRejectsNonPositiveMaxBackups(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AwsProvider(
            awsKey: 'key',
            awsSecret: 'secret',
            awsRegion: 'eu-central-1',
            awsBucket: 'bucket',
            rootPath: 'backup',
            maxBackups: 0,
        );
    }

    public function testSelectObjectsToDeleteKeepsAllWhenWithinLimit(): void
    {
        $contents = [
            ['Key' => 'backup/2024-01-01/a.sql.gz', 'LastModified' => new DateTimeImmutable('2024-01-01')],
            ['Key' => 'backup/2024-01-02/b.sql.gz', 'LastModified' => new DateTimeImmutable('2024-01-02')],
        ];

        self::assertSame([], AwsProvider::selectObjectsToDelete($contents, 3));
    }

    public function testSelectObjectsToDeleteKeepsAllWhenExactlyAtLimit(): void
    {
        $contents = [
            ['Key' => 'a.sql.gz', 'LastModified' => new DateTimeImmutable('2024-01-01')],
            ['Key' => 'b.sql.gz', 'LastModified' => new DateTimeImmutable('2024-01-02')],
        ];

        self::assertSame([], AwsProvider::selectObjectsToDelete($contents, 2));
    }

    public function testSelectObjectsToDeleteReturnsOldestBeyondLimit(): void
    {
        $contents = [
            ['Key' => 'newest.sql.gz', 'LastModified' => new DateTimeImmutable('2024-01-04')],
            ['Key' => 'oldest.sql.gz', 'LastModified' => new DateTimeImmutable('2024-01-01')],
            ['Key' => 'middle.sql.gz', 'LastModified' => new DateTimeImmutable('2024-01-03')],
            ['Key' => 'second.sql.gz', 'LastModified' => new DateTimeImmutable('2024-01-02')],
        ];

        self::assertSame(
            ['oldest.sql.gz', 'second.sql.gz'],
            AwsProvider::selectObjectsToDelete($contents, 2),
        );
    }
}
