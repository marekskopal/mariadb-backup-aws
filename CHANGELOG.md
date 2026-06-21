# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/marekskopal/mariadb-backup-aws/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.3.1...v1.0.0
[0.3.1]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.2.0...v0.3.1
[0.2.0]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/marekskopal/mariadb-backup-aws/releases/tag/v0.1.0
