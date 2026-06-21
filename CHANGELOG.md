# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- GitHub Actions CI workflow running PHPStan, PHPCS and PHPUnit on every push and pull request
- GitHub Actions release workflow building and publishing a multi-arch Docker image to Docker Hub on release

### Changed
- Require PHP >= 8.4 and updated all dependencies to their latest versions
- Updated Dockerfile base images to latest versions (composer 2.10.1, php-extension-installer 2.11.12, php 8.5.7-cli-alpine)

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

[Unreleased]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.2.0...v0.3.1
[0.2.0]: https://github.com/marekskopal/mariadb-backup-aws/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/marekskopal/mariadb-backup-aws/releases/tag/v0.1.0
