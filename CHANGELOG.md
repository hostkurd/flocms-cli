# Changelog

## 1.0.5 - Unreleased

### Fixed
- Unknown commands exit with code 1 (was 0) and print their message on
  STDERR, so scripts and cron jobs can detect the failure.
- Unknown commands explain that the project's root `flo` launcher is
  outdated and link to [UPGRADE.md](UPGRADE.md). Root launchers from FloCMS
  before 1.6 send `api:*` (and other newer commands) to this package, which
  printed "Command not valid". For `api:*` commands the message also shows
  the direct command: `php vendor/bin/flo-api <command>`.
- `bin/flo` returns the command's exit code.

### Notes
- Versions 1.0.3 and 1.0.4 were never tagged; their changes shipped as 1.0.2.

## 1.0.2

- `create:*` and `delete:*` commands for controllers, models, views and routes.
