<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use Aws\S3\S3Client;
use DateTimeImmutable;
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
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $awsRegion,
            'credentials' => [
                'key' => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
    }

    public function upload(string $backupFileName): void
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
    }

    private function checkMaxBackups(): void
    {
        $result = $this->s3Client->listObjectsV2([
            'Bucket' => $this->awsBucket,
            'Prefix' => trim($this->rootPath, '/') . '/',
        ]);

        /** @var list<array{Key: string, LastModified: DateTimeImmutable}> $contents */
        $contents = $result['Contents'] ?? [];
        $objectsCount = count($contents);
        if ($objectsCount <= $this->maxBackups) {
            return;
        }

        usort($contents, fn(array $a, array $b): int => $a['LastModified'] <=> $b['LastModified']);

        $toDelete = array_slice($contents, 0, $objectsCount - $this->maxBackups);
        foreach ($toDelete as $object) {
            $this->s3Client->deleteObject([
                'Bucket' => $this->awsBucket,
                'Key' => $object['Key'],
            ]);
        }
    }
}
