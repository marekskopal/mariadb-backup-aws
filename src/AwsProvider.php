<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use Aws\S3\S3Client;
use DateTimeImmutable;
use DateTimeInterface;
use SensitiveParameter;

final readonly class AwsProvider
{
    private S3Client $s3Client;

    public function __construct(
        #[SensitiveParameter] string $awsKey,
        #[SensitiveParameter] string $awsSecret,
        string $awsRegion,
        private string $awsBucket,
        private string $rootPath,
        private int $maxBackups,
    ) {
        // Guard against a misconfigured limit: with maxBackups <= 0, checkMaxBackups()
        // would treat every object as surplus and delete all backups, including the one
        // just uploaded.
        if ($this->maxBackups < 1) {
            throw new \InvalidArgumentException(
                sprintf('Max backups must be at least 1, %d given', $this->maxBackups),
            );
        }

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $awsRegion,
            'credentials' => [
                'key' => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
    }

    /** Uploads the backup and returns the S3 key it was stored under. */
    public function upload(string $backupFileName): string
    {
        $date = new DateTimeImmutable();
        $datePath = $date->format('Y-m-d');

        $targetPath = implode('/', [
            trim($this->rootPath, '/'),
            $datePath,
            basename($backupFileName),
        ]);

        $this->s3Client->putObject([
            'Bucket' => $this->awsBucket,
            'Key' => $targetPath,
            'SourceFile' => $backupFileName,
            'ServerSideEncryption' => 'AES256',
        ]);

        $this->checkMaxBackups();

        return $targetPath;
    }

    private function checkMaxBackups(): void
    {
        // ListObjectsV2 returns at most 1000 keys per response; iterate the paginator so
        // rotation counts every backup, not just the first page.
        $paginator = $this->s3Client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->awsBucket,
            'Prefix' => trim($this->rootPath, '/') . '/',
        ]);

        $contents = [];
        foreach ($paginator as $result) {
            /** @var list<array{Key: string, LastModified: DateTimeInterface}> $pageContents */
            $pageContents = $result['Contents'] ?? [];
            foreach ($pageContents as $object) {
                $contents[] = $object;
            }
        }

        foreach (self::selectObjectsToDelete($contents, $this->maxBackups) as $key) {
            $this->s3Client->deleteObject([
                'Bucket' => $this->awsBucket,
                'Key' => $key,
            ]);
        }
    }

    /**
     * Returns the keys of the oldest objects that exceed the retention limit.
     *
     * @param list<array{Key: string, LastModified: DateTimeInterface}> $contents
     * @return list<string>
     * @api Exposed for unit testing of the rotation logic.
     */
    public static function selectObjectsToDelete(array $contents, int $maxBackups): array
    {
        $objectsCount = count($contents);
        if ($objectsCount <= $maxBackups) {
            return [];
        }

        usort($contents, fn(array $a, array $b): int => $a['LastModified'] <=> $b['LastModified']);

        return array_map(
            fn(array $object): string => $object['Key'],
            array_slice($contents, 0, $objectsCount - $maxBackups),
        );
    }
}
