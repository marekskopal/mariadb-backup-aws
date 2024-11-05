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
        ]);

        $this->checkMaxBackups();
    }

    private function checkMaxBackups(): void
    {
        /** @var array{Contents: list<array{Key: string, LastModified: DateTimeImmutable}>} $objects */
        $objects = $this->s3Client->listObjects([
            'Bucket' => $this->awsBucket,
            'Prefix' => trim($this->rootPath, '/') . '/',
        ]);

        $objectsCount = count($objects['Contents']);
        if ($objectsCount <= $this->maxBackups) {
            return;
        }

        for ($i = 0; $i < $objectsCount - $this->maxBackups; $i++) {
            $this->deleteOldBackups();
        }
    }

    private function deleteOldBackups(): void
    {
        /** @var array{Contents: list<array{Key: string, LastModified: DateTimeImmutable}>} $objects */
        $objects = $this->s3Client->listObjects([
            'Bucket' => $this->awsBucket,
            'Prefix' => trim($this->rootPath, '/') . '/',
        ]);

        $oldestObject = $objects['Contents'][0];

        foreach ($objects['Contents'] as $object) {
            if ($object['LastModified'] < $oldestObject['LastModified']) {
                $oldestObject = $object;
            }
        }

        $this->s3Client->deleteObject([
            'Bucket' => $this->awsBucket,
            'Key' => $oldestObject['Key'],
        ]);
    }
}
