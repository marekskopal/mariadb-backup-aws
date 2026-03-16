# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Run a single test
./vendor/bin/phpunit --filter TestName

# Static analysis (level 9)
./vendor/bin/phpstan analyse

# Code style check
./vendor/bin/phpcs

# Run backup manually
./bin/console mariaDbBackup:aws [options]
```

## Architecture

This is a PHP CLI tool that dumps MariaDB databases and uploads them to AWS S3, with automatic backup rotation.

**Three core classes:**

- `MariaDbBackupAwsCommand` — Symfony Console command that orchestrates the workflow; reads config from CLI options or environment variables
- `MariaDbDump` — Executes `mariadb-dump ... | gzip > /tmp/YYYY-MM-DD_HH-MM-SS.sql.gz` and cleans up the temp file after upload
- `AwsProvider` — Uploads the gzip to S3 at `{rootPath}/{YYYY-MM-DD}/{filename}`, then enforces the max backup count by deleting oldest entries

**S3 path structure:** `{AWS_ROOT_PATH}/{YYYY-MM-DD}/{timestamp}.sql.gz`

**Backup rotation:** `AwsProvider` lists all objects under the root path, sorts them, and deletes the oldest when the count exceeds `AWS_MAX_BACKUPS` (default: 30).

## Docker

The container runs via Supervisord + Supercronic. The cron schedule (`docker/etc/cron.d/mariadb-backup-aws`) triggers `bin/console mariaDbBackup:aws` at 1:00 AM daily, logging to `/app/log/cron.log`.

```bash
docker-compose up --build   # start container
docker-compose exec mariadb-backup-aws bin/console mariaDbBackup:aws  # manual run
```

## Code conventions

- All classes use `declare(strict_types=1)` and are `final readonly`
- Sensitive parameters (passwords, AWS keys) are annotated with `#[SensitiveParameter]`
- PHPStan level 9, PHP ≥ 8.3
