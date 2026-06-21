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

- `MariaDbBackupAwsCommand` — Symfony Console command that orchestrates the workflow; reads config from CLI options or environment variables (option takes precedence over env var, then default). Wraps dump + upload in `try/finally` so the local dump is always cleaned up.
- `MariaDbDump` — Runs `mariadb-dump` via `proc_open` (array form, no shell) and streams its stdout through gzip into `{sys_get_temp_dir()}/{timestamp}.sql.gz`. Credentials are written to a temporary `--defaults-extra-file` (never passed on the command line, so they don't appear in the process list). The backup file is created `0600`. Checks `mariadb-dump`'s real exit code and fails if the dump produced no output. Supports a single database, a comma-separated list (`--databases`), or all databases when none is given (`--all-databases`).
- `AwsProvider` — Uploads the gzip to S3 at `{rootPath}/{YYYY-MM-DD}/{filename}` with `ServerSideEncryption=AES256`, then enforces the max backup count by deleting the oldest entries.

**S3 path structure:** `{AWS_ROOT_PATH}/{YYYY-MM-DD}/{timestamp}.sql.gz` (timestamp is `Y-m-d_H-i-s` plus a short random suffix to avoid collisions).

**Backup rotation:** `AwsProvider` lists all objects under the root path (paginated, so it is not capped at 1000 keys), sorts them by `LastModified`, and deletes the oldest when the count exceeds `AWS_MAX_BACKUPS` (default: 30). The selection logic lives in the pure, unit-tested `AwsProvider::selectObjectsToDelete()`. `maxBackups` must be ≥ 1 (validated in the constructor).

**Why no shell pipeline:** running `mariadb-dump | gzip` through a shell would surface gzip's exit code, not `mariadb-dump`'s, silently turning a failed dump into an empty "successful" backup. `proc_open` without a shell avoids this and also keeps credentials out of `ps`.

## Docker

The container runs via Supervisord + Supercronic. The cron schedule (`docker/etc/cron.d/mariadb-backup-aws`) triggers `bin/console mariaDbBackup:aws` at 1:00 AM daily, logging to `/app/log/cron.log`.

```bash
docker-compose up --build   # start container
docker-compose exec mariadb-backup-aws bin/console mariaDbBackup:aws  # manual run
```

## Code conventions

- All classes use `declare(strict_types=1)`; the two service classes are `final readonly`
- Sensitive parameters (passwords, AWS keys) are annotated with `#[SensitiveParameter]`
- PHPStan level 9, PHP ≥ 8.4
- Public methods exposed only for unit testing are annotated `@api` (required by the `unused-public` rule)
