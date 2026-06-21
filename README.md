# MariaDB Backup to AWS S3

[![CI](https://github.com/marekskopal/mariadb-backup-aws/actions/workflows/ci.yml/badge.svg)](https://github.com/marekskopal/mariadb-backup-aws/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/marekskopal/mariadb-backup-aws.svg)](https://packagist.org/packages/marekskopal/mariadb-backup-aws)
[![Docker Pulls](https://img.shields.io/docker/pulls/marekskopal/mariadb-backup-aws.svg)](https://hub.docker.com/r/marekskopal/mariadb-backup-aws)
[![PHP Version](https://img.shields.io/packagist/php-v/marekskopal/mariadb-backup-aws.svg)](https://packagist.org/packages/marekskopal/mariadb-backup-aws)
[![License](https://img.shields.io/packagist/l/marekskopal/mariadb-backup-aws.svg)](LICENSE)

A small, dependable CLI tool that dumps one or more MariaDB databases, compresses the dump, uploads it to AWS S3, and rotates old backups automatically. Ships as a Composer package and as a ready-to-run Docker image with a built-in daily schedule.

## Features

- **Streamed gzip dumps** — `mariadb-dump` output is compressed on the fly; no large uncompressed file is kept on disk.
- **Fails loudly, never silently** — the real `mariadb-dump` exit code is checked and an empty dump is rejected, so a broken backup is never uploaded or rotated in.
- **Credentials stay private** — the database password is passed through a temporary `--defaults-extra-file`, never on the command line, so it does not appear in the process list. The temporary dump is written with `0600` permissions and always cleaned up.
- **Encrypted at rest** — uploads request S3 server-side encryption (AES‑256).
- **Automatic rotation** — keeps the newest *N* backups and deletes the rest (paginated, so it scales past 1000 objects).
- **Flexible scope** — back up a single database, a comma-separated list, or all databases.
- **Configurable two ways** — every setting can be provided as a CLI option or an environment variable.

## Requirements

- PHP ≥ 8.4 with the `mariadb-dump` client available on `PATH` (already included in the Docker image)
- An AWS S3 bucket and credentials with `PutObject`, `ListBucket`, and `DeleteObject` permissions

## Installation

### Docker (recommended)

The image is published on [Docker Hub](https://hub.docker.com/r/marekskopal/mariadb-backup-aws) and runs the backup on a daily schedule (01:00) via Supercronic.

```bash
docker pull marekskopal/mariadb-backup-aws:latest
```

### Composer

```bash
composer require marekskopal/mariadb-backup-aws
```

## Configuration

Each option can be set via a CLI flag or an environment variable. CLI options take precedence; if neither is set, the listed default is used (or the command fails for required values).

| CLI option            | Short | Environment variable     | Required | Default  | Description                                                        |
| --------------------- | ----- | ------------------------ | -------- | -------- | ------------------------------------------------------------------ |
| `--host`              | `-H`  | `DB_HOST`                | yes      | —        | MariaDB host                                                       |
| `--user`              | `-u`  | `DB_USER`                | yes      | —        | MariaDB user                                                       |
| `--password`          | `-p`  | `DB_PASSWORD`            | yes      | —        | MariaDB password                                                   |
| `--database`          | `-d`  | `DB_DATABASE`            | no       | *(all)*  | Database name; comma-separated for several; all databases if empty |
| `--aws-access-key`    | `-a`  | `AWS_ACCESS_KEY`         | yes      | —        | AWS access key                                                     |
| `--aws-secret`        | `-s`  | `AWS_SECRET_ACCESS_KEY`  | yes      | —        | AWS secret access key                                              |
| `--aws-region`        | `-r`  | `AWS_REGION`             | yes      | —        | AWS region                                                         |
| `--aws-bucket`        | `-b`  | `AWS_BUCKET`             | yes      | —        | S3 bucket name                                                     |
| `--aws-root-path`     | `-o`  | `AWS_ROOT_PATH`          | no       | `backup` | Key prefix for backups within the bucket                           |
| `--aws-max-backups`   | `-m`  | `AWS_MAX_BACKUPS`        | no       | `30`     | Number of backups to retain (must be ≥ 1)                          |

Backups are stored under:

```
{AWS_ROOT_PATH}/{YYYY-MM-DD}/{timestamp}.sql.gz
```

## Usage

### Docker Compose

Define the configuration in your `.env` file:

```env
DB_HOST=your_db_host
DB_USER=your_db_user
DB_PASSWORD=your_db_password
DB_DATABASE=your_db_name

AWS_ACCESS_KEY=your_aws_key
AWS_SECRET_ACCESS_KEY=your_aws_secret
AWS_REGION=your_aws_region
AWS_BUCKET=your_aws_bucket
AWS_ROOT_PATH=backup
AWS_MAX_BACKUPS=30
```

Add the service to your `docker-compose.yml`:

```yaml
services:
    mariadb-backup-aws:
        image: marekskopal/mariadb-backup-aws:latest
        environment:
            DB_HOST: ${DB_HOST}
            DB_DATABASE: ${DB_DATABASE}
            DB_USER: ${DB_USER}
            DB_PASSWORD: ${DB_PASSWORD}
            AWS_ACCESS_KEY: ${AWS_ACCESS_KEY}
            AWS_SECRET_ACCESS_KEY: ${AWS_SECRET_ACCESS_KEY}
            AWS_REGION: ${AWS_REGION}
            AWS_BUCKET: ${AWS_BUCKET}
            AWS_ROOT_PATH: ${AWS_ROOT_PATH:-backup}
            AWS_MAX_BACKUPS: ${AWS_MAX_BACKUPS:-30}
        restart: unless-stopped
```

```bash
docker compose up -d
```

The container runs the backup every day at 01:00 (logs to `/app/log/cron.log`). To run it once on demand:

```bash
docker compose exec mariadb-backup-aws bin/console mariaDbBackup:aws
```

### Composer (CLI)

```bash
./vendor/marekskopal/mariadb-backup-aws/bin/console mariaDbBackup:aws \
  --host=your_db_host \
  --user=your_db_user \
  --password=your_db_password \
  --database=your_db_name \
  --aws-access-key=your_aws_key \
  --aws-secret=your_aws_secret \
  --aws-region=your_aws_region \
  --aws-bucket=your_aws_bucket
```

Omit `--database` to back up all databases, or pass a comma-separated list (e.g. `--database=app,reporting`).

## How it works

1. `mariadb-dump` is launched via `proc_open` (no shell), with credentials supplied through a temporary `0600` option file.
2. Its stdout is streamed through gzip into a temporary `0600` file. The exit code and output are verified.
3. The compressed dump is uploaded to S3 with AES‑256 server-side encryption.
4. Objects under the prefix are listed and the oldest beyond `AWS_MAX_BACKUPS` are deleted.
5. The local temporary file is removed — even if the dump or upload fails.

## Development

```bash
composer install

vendor/bin/phpunit      # tests
vendor/bin/phpstan analyse   # static analysis (level 9)
vendor/bin/phpcs        # coding standard
```

## License

Released under the [MIT License](LICENSE).
