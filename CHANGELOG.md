# Changelog

## 2.0.1 - Unreleased

### Fixed
- **Hidden input on Windows.** Password prompts (`user:create`,
  `user:password`) ran PowerShell with `-NonInteractive`, where `Read-Host`
  fails, so the prompt errored out or appeared to be skipped. PowerShell
  now runs interactively; if hidden input still fails, the prompt says the
  input will be visible and asks again.

## 2.0.0 - Unreleased

A new architecture: one command kernel for FloCMS, its packages and the
application. Requires PHP 8.1 and hostkurd/flocms-core 2.2. No third-party
runtime dependencies. See [UPGRADE.md](UPGRADE.md).

### Kernel (A1-A9)
- One command registry instead of the hard-coded `switch`. Commands are
  classes (`FloCMS\CLI\Console\Command`) with declared arguments and options.
- Packages register commands, migrations and route providers in
  composer.json `"extra": {"flocms": {...}}` (read from
  `vendor/composer/installed.json`); the application's `commands/` folder
  (`App\Commands`) is discovered automatically.
- The project's root `flo` is a three-line launcher
  (`FloCMS\CLI\Kernel::handle(__DIR__, $argv)`), so `composer update`
  delivers new commands; `vendor/bin/flo` boots the same kernel, so older
  launchers keep working.
- Arguments (required, optional, arrays) and options (flags, values,
  defaults, repeatable, shortcuts, combined `-fd`, `--name value`), with
  clear errors and the usage line.
- Global options: `-h|--help`, `-q|--quiet`, `-v|-vv|-vvv`, `-V|--version`,
  `--ansi`/`--no-ansi`, `-n|--no-interaction`, `--env=`.
- `list` (grouped, `--format=json`) and `help <command>`; "Did you mean"
  suggestions and unambiguous abbreviations (`mig` -> `migrate`).
- Exit codes 0 / 1 / 2 (invalid usage); errors on STDERR as a readable
  block, stack traces with `-vvv` or `APP_DEBUG=true`.
- The project root comes from the launcher, never the current directory
  (`php ../flo make:model X` from a subfolder wrote to `sub/models/`).
- Names are validated and paths confined to the project
  (`create:controller ../../escaped` wrote outside it).
- Output toolkit: styles, tables, definition lists, progress bars,
  spinners, prompts (`ask`, `confirm`, `choice`, hidden `secret`); colors
  only on terminals, `NO_COLOR` and Windows VT100 support.
- Versions from Composer (`-V`: CLI, core, api, application) instead of the
  hard-coded "Version 1.8".
- `key:generate [--force] [--show]` moved here from the skeleton.
- `CommandTester` and `ApplicationTester` for testing commands.

### Commands (B1-B10)
- Generators: `make:controller|model|view|route|api-controller|resource|
  middleware|request|migration|seeder|command|test|module`, all with
  `--force` and `--dry-run`; project-overridable stubs (`stub:publish`).
  Generated actions are lowercase with return types and 4-space indents.
  `create:*` remain as aliases; `delete:*` (alias `remove:*`) list what they
  change and ask first.
- Migrations: `migrate`, `migrate:status`, `migrate:rollback --step`,
  `migrate:fresh`, `db:seed`, `db:show`, `db:check`; MySQL/MariaDB and
  SQLite; package migrations from `vendor/`.
- `about`, `doctor`, `serve`, `down`/`up` (maintenance mode), `env:check`.
- `cache:clear`, `view:clear`, `optimize`, `optimize:clear`.
- `route:list` (web and API routes, permissions), `permission:list`.
- `user:create`, `user:password`, `user:role`, `user:suspend`,
  `user:unsuspend`, `user:list`.
- `lang:missing`, `lang:add`.
- `schedule:run` / `schedule:list`: one cron line for cPanel, tasks in
  `config/schedule.php`, overlap locks, built-in `api:gc`, log rotation and
  upload cleanup.
- `log:tail`, `log:clear`, `log:rotate`, `storage:check`.
- `completion bash|zsh|fish|powershell`.

### Quality (C1-C3)
- PHPUnit tests for the kernel, parser, discovery, every command, path
  safety, exit codes and Windows paths; GitHub Actions on PHP 8.1-8.4 on
  Linux and Windows, plus MySQL 8.0 and MariaDB 10.11.
- composer.json: `php ^8.1`, PHPUnit as require-dev, branch alias, keywords,
  support URLs.

### Removed
- `Gate`, `Core`, `Help`, `Colors` and `src/data.json` (the 1.x internals).
- `--v` (use `-V`/`--version`) and `--help` printing only the 1.x commands.

## 1.0.5 - Unreleased

### Fixed
- Unknown commands exit with code 1 (was 0) and print their message on
  STDERR, so scripts and cron jobs can detect the failure.
- Unknown commands explain that the project's root `flo` launcher is
  outdated and link to [UPGRADE.md](UPGRADE.md). For `api:*` commands the
  message also shows the direct command: `php vendor/bin/flo-api <command>`.
- `bin/flo` returns the command's exit code.

### Notes
- Versions 1.0.3 and 1.0.4 were never tagged; their changes shipped as 1.0.2.

## 1.0.2

- `create:*` and `delete:*` commands for controllers, models, views and routes.
