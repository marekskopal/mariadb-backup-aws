# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install dependencies
composer install

# Run unit tests and the console entrypoint test
./vendor/bin/phpunit --testsuite default

# Run a single test
./vendor/bin/phpunit --filter TestName

# Run the end-to-end suite against a simulated AWS (MariaDB + MinIO)
docker compose -f docker-compose.test.yml up -d --wait
set -a && . ./.env.test && set +a
./vendor/bin/phpunit --testsuite integration
docker compose -f docker-compose.test.yml down -v

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
- `MariaDbDump` — Runs `mariadb-dump` via `proc_open` (array form, no shell) and streams its stdout through gzip into `{sys_get_temp_dir()}/{timestamp}.sql.gz`. Credentials and port are written to a temporary `--defaults-extra-file` (never passed on the command line, so they don't appear in the process list). The backup file is created `0600`. Dumps with consistent/complete defaults (`--single-transaction --routines --events --triggers`); extra options can be appended via `extraDumpOptions`. Checks `mariadb-dump`'s real exit code and fails if the dump produced no output. Supports a single database, a comma-separated list (`--databases`), or all databases when none is given (`--all-databases`).
- `AwsProvider` — Uploads the gzip to S3 at `{rootPath}/{YYYY-MM-DD}/{filename}` with `ServerSideEncryption=AES256`, then enforces the max backup count by deleting the oldest entries. An optional `awsEndpoint` (`--aws-endpoint` / `AWS_ENDPOINT`) targets an S3-compatible server; a custom endpoint also switches on path-style addressing, since such servers cannot serve AWS's virtual-hosted `{bucket}.{host}` style. The config is built by the pure, unit-tested `AwsProvider::createClientConfig()`.

**`bin/console` is covered by a test on purpose:** v0.3.1 and v1.0.0 shipped an entrypoint calling `Application::add()`, removed in symfony/console 8 (`addCommand()` now). Every invocation died with a PHP fatal error, yet the suite stayed green because every test constructed the command class directly. `tests/ConsoleEntrypointTest.php` runs the real binary in a subprocess, which is the only way to catch that class of breakage; `tests/Support/ConsoleRunner.php` is the shared helper.

**S3 path structure:** `{AWS_ROOT_PATH}/{YYYY-MM-DD}/{timestamp}.sql.gz` (timestamp is `Y-m-d_H-i-s` plus a short random suffix to avoid collisions).

**Backup rotation:** `AwsProvider` lists all objects under the root path (paginated, so it is not capped at 1000 keys), sorts them by `LastModified`, and deletes the oldest when the count exceeds `AWS_MAX_BACKUPS` (default: 30). The selection logic lives in the pure, unit-tested `AwsProvider::selectObjectsToDelete()`. `maxBackups` must be ≥ 1 (validated in the constructor).

**Why no shell pipeline:** running `mariadb-dump | gzip` through a shell would surface gzip's exit code, not `mariadb-dump`'s, silently turning a failed dump into an empty "successful" backup. `proc_open` without a shell avoids this and also keeps credentials out of `ps`.

## Docker

The container runs via Supervisord + Supercronic. The cron schedule (`docker/etc/cron.d/mariadb-backup-aws`) triggers `bin/console mariaDbBackup:aws` at 1:00 AM daily, logging to `/app/log/cron.log`.

```bash
docker-compose up --build   # start container
docker-compose exec mariadb-backup-aws bin/console mariaDbBackup:aws  # manual run
```

## Testing

Three layers, and the layering matters:

- **Unit** (`tests/`, suite `default`) — pure logic: command builders, option-file content, rotation selection, client config.
- **Entrypoint** (`tests/ConsoleEntrypointTest.php`, suite `default`) — spawns `bin/console` itself. Requires no database or S3.
- **End-to-end** (`tests/Integration/`, suite `integration`) — the published binary against a live MariaDB and MinIO (an S3-compatible stand-in for S3). Skips itself unless `MARIADB_TEST_*` and `S3_TEST_*` are set; `docker-compose.test.yml` + `.env.test` provide them, and CI runs the same Compose stack (job services cannot override the MinIO image's `server /data` command).

`docker-compose.test.yml` gives MinIO a `MINIO_KMS_SECRET_KEY` so it honours the AES-256 SSE header the uploader sends; without a KMS key MinIO rejects it.

The end-to-end suite pins down the failure modes seen in production: a failed dump must upload nothing (v0.2.0 uploaded an empty gzip and rotated good backups out), rotation must delete only the oldest surplus objects, and option-file quoting must survive a password containing a quote, a backslash, a `#` and a trailing space — unquoted, the client trims that trailing space and authentication fails, so the test is a real canary for the escaping.

Because `composer.lock` is not committed, the Docker image resolves dependencies at build time; that is how a `^8.1` console ended up under `add()`-era code. CI's `docker-smoke` job and the release workflow both run the entrypoint from the built image before it is published.

## Code conventions

- All classes use `declare(strict_types=1)`; the two service classes are `final readonly`
- Sensitive parameters (passwords, AWS keys) are annotated with `#[SensitiveParameter]`
- PHPStan level 9, PHP ≥ 8.4
- Public methods exposed only for unit testing are annotated `@api` (required by the `unused-public` rule)
