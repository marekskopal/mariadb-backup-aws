# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.2] - 2026-10-21

Maintenance release: PHP base image update and test tooling changes. No functional changes to the backup workflow.

### Changed
- Updated the Docker base image to php 8.5.12-cli-alpine
- Replaced `tomasvotruba/unused-public` with `shipmonk/dead-code-detector` for dead-code detection in static analysis
- End-to-end tests now use the `pgsty/minio` image (RELEASE.2026-08-04T00-00-00Z) as the S3-compatible stand-in

## [1.0.1] - 2026-09-10

Fixes a release-blocking fatal error in the console entrypoint: v0.3.1 and v1.0.0 could
not run at all. Anyone on either version should upgrade.

### Fixed
- **`bin/console` no longer fatals on every invocation.** The entrypoint called `Application::add()`, which symfony/console 8 removed in favour of `addCommand()`, so v0.3.1 and v1.0.0 (Composer package and Docker image alike) died with `PHP Fatal error: Call to undefined method Symfony\Component\Console\Application::add()` before any backup ran. No test executed the binary, so the suite stayed green.

### Added
- `tests/ConsoleEntrypointTest.php` runs the real `bin/console` in a subprocess, so an entrypoint incompatible with the installed symfony/console fails the build
- End-to-end test suite exercising the published binary against a live MariaDB and MinIO (an S3-compatible stand-in for AWS S3): asserts the uploaded object is restorable gzip containing the seeded data, that the reported key matches the stored object, that AES-256 encryption is applied, that rotation deletes only the oldest surplus backups, and that a failed dump uploads nothing at all
- `docker-compose.test.yml` and `.env.test`, a ready-made MariaDB + MinIO environment for that suite, used unchanged by CI
- `--aws-endpoint` / `AWS_ENDPOINT` for S3-compatible storage (MinIO, LocalStack, Ceph); a custom endpoint switches on path-style addressing
- CI job that runs the entrypoint from the built Docker image, and the same smoke test in the release workflow before the image is pushed

### Changed
- Updated Composer dependencies to their latest versions (aws/aws-sdk-php 3.394.11, symfony/console 8.1.6, PHPUnit 13.3.3, PHPStan 2.2.13, Slevomat Coding Standard 8.31.1)
- Updated Docker base images to their latest versions (composer 2.10.3, php 8.5.10-cli-alpine) and MinIO in the test environment to RELEASE.2025-09-07T16-13-09Z

## [1.0.0] - 2026-06-21

First stable release, hardening the backup workflow for production use.

### Added
- Consistent, complete dumps by default (`--single-transaction --routines --events --triggers`), with extra `mariadb-dump` options configurable via `--dump-options` / `DB_DUMP_OPTIONS`
- Database port support via `--port` / `DB_PORT` (default 3306)
- Multiple-database and all-databases support (comma-separated list, or all databases when none is given)
- S3 server-side encryption (AES-256) for uploaded backups
- Console progress output reporting the uploaded S3 key
- GitHub Actions CI (PHPStan, PHPCS, PHPUnit) and a release workflow publishing a multi-arch Docker image to Docker Hub
- Integration test running the real dump against a MariaDB service container

### Changed
- Database credentials are passed through a temporary `--defaults-extra-file` instead of the command line, so they no longer appear in the process list
- The temporary dump is created with `0600` permissions and always cleaned up, even on failure
- Backup rotation now paginates the S3 listing, so it is no longer capped at 1000 objects
- `maxBackups` must be at least 1 (validated on construction)
- Empty option/environment values are treated as missing
- Require PHP >= 8.4 and updated all dependencies to their latest versions
- Updated Dockerfile base images to latest versions (composer 2.10.1, php-extension-installer 2.11.12, php 8.5.7-cli-alpine)

### Fixed
- A failed `mariadb-dump` is no longer reported as success: the dump runs without a shell pipeline so its real exit code is checked, and an empty dump is rejected before upload (previously a failed dump could upload an empty backup and rotate out good ones)

## [0.3.1] - 2026-03-16

### Changed
- Updated Composer and Docker dependencies
- Added project guidance file (`CLAUDE.md`)

### Fixed
- Various bug fixes

## [0.2.0] - 2025-01-28

### Changed
- Updated Docker images
- Updated PHPUnit and PHPStan

## [0.1.0] - 2024-11-06

### Added
- Initial release: dump MariaDB databases and upload them to AWS S3 with automatic backup rotation
- Command options for configuration

### Fixed
- Cron file
- Backup deletion (rotation)

[Unreleased]: https://github.com/marekskopal/mariadb-backup-aws/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/marekskopal/mariadb-backup-aws/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/marekskopal/mariadb-backup-aws/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.3.1...v1.0.0
[0.3.1]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.2.0...v0.3.1
[0.2.0]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/marekskopal/mariadb-backup-aws/releases/tag/v0.1.0
