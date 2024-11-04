<?php

declare(strict_types=1);

namespace MarekSkopal\MariaDbBackup;

use Aws\S3\S3Client;

final readonly class AwsProvider
{
    public function __construct(private string $awsKey, private string $awsSecret, private string $awsRegion, private string $awsBucket,)
    {
    }

    public function upload(string $backupFilePath): void
    {
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => $this->awsRegion,
            'credentials' => [
                'key' => $this->awsKey,
                'secret' => $this->awsSecret,
            ],
        ]);

        $s3->putObject([
            'Bucket' => $this->awsBucket,
            'Key' => basename($backupFilePath),
            'SourceFile' => $backupFilePath,
        ]);
    }
}
