# flocms-cli

The command line of [FloCMS](https://github.com/hostkurd/FloCMS): one command
kernel for the framework, its packages and your application.

```
php flo                      # every command, grouped
php flo make:controller Blog --resource --model
php flo migrate
php flo doctor               # is this install healthy?
```

- One kernel: built-in commands, package commands (e.g. flocms-api's `api:*`)
  and your own `commands/` folder, all under `php flo`.
- Generators, migrations, seeders, users, maintenance mode, a cron scheduler,
  route and permission lists, translation checks, log tools and shell
  completion.
- Clear errors on STDERR and real exit codes: 0 success, 1 failure, 2 invalid
  usage.
- No third-party runtime dependencies. PHP 8.1+, Linux, macOS and Windows
  (XAMPP).

## Contents

- [Installation](#installation)
- [Usage](#usage)
- [Command reference](#command-reference)
- [Generators and stubs](#generators-and-stubs)
- [Migrations and seeders](#migrations-and-seeders)
- [Users](#users)
- [Maintenance mode](#maintenance-mode)
- [Scheduler](#scheduler)
- [Writing commands](#writing-commands)
- [Commands, migrations and routes from packages](#commands-migrations-and-routes-from-packages)
- [Testing commands](#testing-commands)
- [Shell completion](#shell-completion)
- [Upgrading from 1.x](#upgrading-from-1x)

## Installation

FloCMS 1.7+ requires it already. On other projects:

```
composer require hostkurd/flocms-cli:2.0.0
```

The project's root `flo` file boots the kernel:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';
exit(FloCMS\CLI\Kernel::handle(__DIR__, $argv));
```

All logic lives in `vendor/`, so `composer update` delivers new commands and
fixes. `vendor/bin/flo` works too.

## Usage

```
php flo <command> [options] [arguments]
php flo list [namespace] [--format=json]
php flo help <command>              # or: php flo <command> --help
```

**Global options** (every command):

| Option | Meaning |
|---|---|
| `-h, --help` | Show the command's usage, arguments, options and examples |
| `-q, --quiet` | Only print errors |
| `-v`, `-vv`, `-vvv` | More output; `-vvv` adds stack traces |
| `-V, --version` | CLI and framework versions |
| `--ansi` / `--no-ansi` | Force or disable colors (`NO_COLOR` is respected) |
| `-n, --no-interaction` | Never ask; use defaults (commands that need an answer fail) |
| `--env=ENV` | Run with `APP_ENV=ENV` |

- **Abbreviations.** Unambiguous abbreviations work: `php flo mig` runs
  `migrate`, and `php flo m:st` runs `migrate:status`.
- **Typos.** A mistyped command suggests the closest names ("Did you mean
  `api:install-schema`?").
- **Exit codes.** `0` success, `1` failure, `2` invalid usage (missing
  argument, unknown option). Errors go to STDERR. Stack traces appear with
  `-vvv` or `APP_DEBUG=true`.
- **Project root.** The project is the directory of the `flo` launcher, so
  `php ../flo make:model Post` from a subfolder still writes to `models/`.

## Command reference

### Application

| Command | Description |
|---|---|
| `about [--format=json]` | PHP, extensions, package versions, environment, database, paths |
| `doctor [--format=json]` | Install health check: PHP 8.1+, extensions (pdo, mbstring, fileinfo, gd), writable `storage/` and `views/cache/`, `.env`, `APP_KEY`, `APP_URL`, `APP_DEBUG` in production, database, pending migrations, rewrite rules, document root. Exit 1 when a check fails |
| `key:generate [--force] [--show]` | Set `APP_KEY` in `.env` (`--force` replaces it; asks on production) |
| `env:check` | Keys of `.env.example` missing from `.env` |
| `serve [--host=127.0.0.1] [--port=8000]` | PHP's built-in server for `public/`, routed like `.htaccess` (development only) |
| `down [--message=] [--retry=] [--allow=IP]` / `up` | Maintenance mode, see [below](#maintenance-mode) |
| `completion bash\|zsh\|fish\|powershell` | Shell completion script |

### Generators (`make:*`)

All generators take `--force` (overwrite) and `--dry-run` (show what would be
written; add `-v` to see the contents).

| Command | Creates |
|---|---|
| `make:controller <Name> [--admin] [--resource] [--model] [--api]` | `controllers/NameController.php` |
| `make:model <Name> [--table=] [--migration]` | `models/NameModel.php` (and wires an existing controller) |
| `make:view <controller> [view] [--admin]` | `views/<controller>/<view>.html` plus the controller action |
| `make:route <Name> [view]` | Controller, model and view |
| `make:api-controller <Name> [--resource]` | `api/Controllers/NameController.php` |
| `make:resource <Name>` | `api/Resources/NameResource.php` (JsonResource) |
| `make:middleware <Name>` | `api/Middleware/Name.php` |
| `make:request <Name>` | `api/Requests/NameRequest.php` (validation rules) |
| `make:migration <name> [--create=table] [--table=table]` | `database/migrations/<timestamp>_<name>.php` |
| `make:seeder <Name>` | `database/seeders/NameSeeder.php` |
| `make:command <Name> [--command=app:name]` | `commands/NameCommand.php` |
| `make:test <Name> [--feature]` | `tests/Unit/NameTest.php` or `tests/Feature/` |
| `make:module <id>` | `modules/<id>/module.php` and a service provider |
| `stub:publish [stubs...] [--force]` | Copies the stubs to `stubs/` for customising |

The 1.x names `create:controller`, `create:model`, `create:view` and
`create:route` still work.

`delete:controller|model|view|route` (aliases `remove:*`) show every file
they will delete or edit and ask first. `--force` skips the question, and
`--dry-run` only shows. They also remove the action or the model wiring from
the controller.

### Database

| Command | Description |
|---|---|
| `migrate [--package=] [--pretend] [--seed] [--force]` | Run pending migrations (asks on production) |
| `migrate:status [--package=]` | Which migrations ran, are pending, changed or missing |
| `migrate:rollback [--step=N] [--pretend]` | Undo the last batch (or the last N migrations) |
| `migrate:fresh [--seed] [--force]` | Drop **all** tables and migrate again (asks; refuses on production without `--force`) |
| `db:seed [--class=DatabaseSeeder]` | Run a seeder |
| `db:show [--format=json]` | Connection and tables with row counts |
| `db:check` | Is the database configured and reachable? |

### Cache

| Command | Description |
|---|---|
| `cache:clear [--all]` | Empty `storage/cache` (module caches kept unless `--all`) |
| `view:clear` | Remove compiled templates from `views/cache` |
| `optimize` | Compile every view and template ahead of time |
| `optimize:clear` | `view:clear` + `cache:clear` |

### Inspection

| Command | Description |
|---|---|
| `route:list [--method=] [--path=] [--name=] [--source=] [--format=json]` | Convention routes of `controllers/` (`admin_` actions, permissions from `$actionPermissions`) and `/api/v1` routes in one table |
| `permission:list [--format=json]` | Roles and their permissions, and which roles can run each controller action |

### Users, translations, scheduler, logs

| Command | Description |
|---|---|
| `user:create [--role=super-admin]` | Create a user interactively (hidden password) |
| `user:password <email>`, `user:role <email> <role>` | Change a password or role |
| `user:suspend <email>`, `user:unsuspend <email>`, `user:list` | Manage users |
| `lang:missing [--lang=ar]` | Translation keys missing between `lang/en.php` and other languages; checks `LANGUAGES` |
| `lang:add <locale> [--rtl]` | New `lang/<locale>.php` with empty values |
| `schedule:run`, `schedule:list` | The cron scheduler |
| `log:tail [log] [--lines=50] [--follow]`, `log:clear [log]`, `log:rotate` | `storage/logs` tools |
| `storage:check [--fix]` | Are the storage directories writable? |

Packages add their own: with flocms-api installed, `php flo list api` shows
`api:install-schema`, `api:key:create`, `api:token:create`, `api:routes`,
`api:docs`, `api:gc`, and more.

## Generators and stubs

Generated code follows FloCMS conventions:

- **URLs.** `BlogPostController` answers `/blog-post`. Admin actions are
  named `admin_index()` and served at `/admin/blog-post`. Views live in
  `views/blog-post/<action>.html`.
- **Models.** They are reached through a lazy `model()` accessor, so pages
  work before a database exists.
- **Code style.** Actions are lowercase with return types, and indentation is
  4 spaces.

Names are validated: letters, digits, `_` and `-`, starting with a letter.
`blog-post` becomes `BlogPost`. Nothing can be written outside the project.

To customise a template, publish it and edit `stubs/<name>.stub`; the
generators use your copy:

```
php flo stub:publish controller model
```

Placeholders look like `{{ class }}`, `{{ table }}` and `{{ route }}`.

## Migrations and seeders

The database comes from `.env`:

- **MySQL/MariaDB:** `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USERNAME`,
  `DB_PASSWORD`, `DB_CHARSET`.
- **SQLite:** `DB_TYPE=sqlite` with `DB_NAME=storage/database.sqlite`.
- **Any PDO driver:** `DB_DSN`.

```
php flo make:migration create_posts_table
```

```php
<?php
declare(strict_types=1);

use FloCMS\CLI\Database\Migration;
use FloCMS\Core\Database;

return new class extends Migration
{
    public function up(Database $db): void
    {
        $db->query('CREATE TABLE IF NOT EXISTS `posts` (
            ' . $this->id($db) . ',
            `title` VARCHAR(190) NOT NULL,
            `created_at` DATETIME NULL
        )' . $this->tableOptions($db));
    }

    public function down(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS `posts`');
    }
};
```

- **Order and batches.** Migrations run in file-name order as numbered
  batches. They are recorded in the `migrations` table with a checksum, so
  `migrate:status` shows edited or missing files.
- **Formats.** Core's module migration array format also works:
  `['up' => [...SQL...], 'down' => [...]]`.
- **Locking.** Only one `migrate` runs at a time (a file lock, plus
  `GET_LOCK` on MySQL).
- **Production.** On `APP_ENV=production`, data-changing commands ask first;
  deployments pass `--force`.

Seeders live in `database/seeders` (namespace `Database\Seeders`):

```php
final class DatabaseSeeder extends \FloCMS\CLI\Database\Seeder
{
    public function run(\FloCMS\Core\Database $db): void
    {
        $db->table('posts')->insert(['title' => 'Hello']);
        $this->call(UsersSeeder::class);
    }
}
```

## Users

`user:create` is the safe way to create the first admin on a fresh site. It
asks for the name, email and password (hidden, asked twice). For scripts,
never put a password on the command line:

```
echo "$ADMIN_PASSWORD" | php flo user:create -n --name="Admin" --email=admin@example.com --role=super-admin --password-stdin
```

Roles: `user` (0), `editor` (1), `admin` (2), `super-admin` (3), or the names
in config `roles`.

## Maintenance mode

```
php flo down --message="Upgrading, back in 10 minutes" --retry=600 --allow=203.0.113.7
php flo up
```

- **The flag.** `down` writes `storage/framework/down.json`. FloCMS 1.7's
  `public/index.php` and `public/api.php` answer 503 (with `Retry-After`) to
  everyone except the allowed IPs and CIDR ranges.
- **The database setting.** When the database is reachable, `down` also turns
  on the `offline_mode` setting.

## Scheduler

Add **one** cron job. In cPanel, use Cron Jobs → Once Per Minute:

```
* * * * * php /home/USER/site/flo schedule:run >> /dev/null 2>&1
```

Define tasks in `config/schedule.php`:

```php
<?php
use FloCMS\CLI\Scheduling\Schedule;

return static function (Schedule $schedule): void {
    $schedule->defaults(); // api:gc, log rotation, stale upload cleanup

    $schedule->command('reports:send', ['--weekly'])->weeklyOn(1, '08:00');
    $schedule->command('backup:run')->dailyAt('02:30')->environments('production');
    $schedule->call(static fn () => touch(__DIR__ . '/../storage/heartbeat'), 'heartbeat')->everyFiveMinutes();
    $schedule->command('feeds:import')->cron('*/20 6-22 * * mon-fri')->timezone('Asia/Baghdad');
};
```

- **Frequencies:** `everyMinute`, `everyFiveMinutes`, `everyTenMinutes`,
  `everyFifteenMinutes`, `everyThirtyMinutes`, `hourly`, `hourlyAt(m)`,
  `daily`, `dailyAt('HH:MM')`, `twiceDaily`, `weekly`, `weeklyOn(day, time)`,
  `monthly`, `monthlyOn(day, time)`, `yearly`, `weekdays`, `cron('...')`.
- **No overlaps.** A task still running is not started again; use
  `allowOverlapping()` to change that.
- **Where tasks run.** Tasks run inside the `schedule:run` process, so
  `proc_open` is not needed.
- **Results** go to `storage/logs/schedule.log`. `schedule:list` shows the
  next run and the last result.
- **Testing.** Use `php flo schedule:run --task="name"` to run one task now.

Without `config/schedule.php` the built-in tasks run:

- `api:gc` daily, when flocms-api is installed.
- Log rotation: logs over 10 MB, keeping 5 copies.
- Deletion of chunked uploads older than 24 hours in `storage/uploads/.chunks`.

## Writing commands

Classes in `commands/` (namespace `App\Commands`) are discovered
automatically. `php flo make:command SendReports` creates one:

```php
<?php
declare(strict_types=1);

namespace App\Commands;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class SendReportsCommand extends Command
{
    protected string $name = 'reports:send';

    protected string $description = 'Email the weekly reports';

    protected array $examples = ['php flo reports:send --dry-run'];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('team', Argument::OPTIONAL, 'Only this team', 'all')
            ->option('dry-run', null, Option::VALUE_NONE, 'Do not send')
            ->option('to', 't', Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY, 'Extra recipients');
    }

    protected function handle(): int
    {
        $rows = $this->project()->database()->table('reports')->get();
        $bar = $this->progress(count($rows));
        foreach ($rows as $row) {
            // ...
            $bar->advance();
        }
        $bar->finish();
        $this->success(count($rows) . ' reports sent.');

        return self::SUCCESS;
    }
}
```

**Arguments** can be required, optional or arrays (`Argument::IS_ARRAY` takes
the rest). **Options** can be flags (`VALUE_NONE`), take a value
(`VALUE_REQUIRED` / `VALUE_OPTIONAL`), and be repeated (`VALUE_IS_ARRAY`),
with defaults and one-letter shortcuts.

**Helpers inside `handle()`:**

- **Input:** `argument()`, `option()`.
- **Output:** `line()`, `info()`, `comment()`, `success()`, `warn()`,
  `note()`, `error()`, `title()`, `section()`, `table()`,
  `definitionList()`, `progress()`, `spin()`.
- **Prompts:** `ask()`, `confirm()`, `choice()`, `secret()`. Under
  `--no-interaction` they use the default.
- **Other:** `call('other:command', [...])`, `fail('message')` (exit 1
  without a stack trace), `invalid('message')` (exit 2), and `project()`
  (root, paths, `.env`, database).

Style tags: `<info>`, `<comment>`, `<error>`, `<warning>`, `<success>`,
`<muted>`, `<b>`, `<fg=red;bg=white;options=bold>`.

## Commands, migrations and routes from packages

A package registers everything in its `composer.json`. The kernel reads
`vendor/composer/installed.json`, so nothing needs to be forwarded or copied:

```json
"extra": {
    "flocms": {
        "commands": ["Vendor\\Package\\Console\\SyncCommand"],
        "migrations": ["database/migrations"],
        "route-providers": ["Vendor\\Package\\Console\\Routes"]
    }
}
```

- `commands` are `FloCMS\CLI\Console\Command` classes.
- `migrations` are directories run by `migrate` straight from `vendor/`,
  tracked per package (`migrate --package=vendor/package`).
- `route-providers` implement `FloCMS\CLI\Routing\RouteProvider` and add rows
  to `route:list`.

A project can use the same keys in its own `composer.json`. A later
registration with the same name wins, so `commands/` can override a built-in
command.

## Testing commands

`FloCMS\CLI\Console\Testing\CommandTester` and `ApplicationTester` run
commands with captured STDOUT, STDERR and exit code, and feed answers to
prompts:

```php
use FloCMS\CLI\Console\Testing\ApplicationTester;

$tester = ApplicationTester::forProject(dirname(__DIR__, 2));
$tester->run(['user:create', '--role=editor'], ['Ada', 'ada@example.com', 'secret-pass', 'secret-pass']);

self::assertSame(0, $tester->getStatusCode());
self::assertStringContainsString('created', $tester->getDisplay());
```

```php
$tester = new CommandTester(new SendReportsCommand(), new FloCMS\CLI\Project($root));
$tester->execute(['team' => 'sales', '--dry-run' => true]);
```

## Shell completion

```
php flo completion bash >> ~/.bashrc                               # bash
php flo completion zsh > ~/.zfunc/_flo                             # zsh
php flo completion fish > ~/.config/fish/completions/flo.fish      # fish
php flo completion powershell | Out-String | Invoke-Expression     # PowerShell (add to $PROFILE)
```

Command names, aliases and options complete, including your own and your
packages' commands.

## Upgrading from 1.x

See [UPGRADE.md](UPGRADE.md). In short:

1. Require `hostkurd/flocms-cli` `2.0.0`.
2. Replace the project's root `flo` file with the three-line launcher.
3. Run `composer update`.

## Development

```
composer install
vendor/bin/phpunit                       # unit + integration (SQLite)
FLO_TEST_MYSQL_HOST=127.0.0.1 FLO_TEST_MYSQL_PASS=root vendor/bin/phpunit --testsuite mysql
```

CI runs PHP 8.1–8.4 on Linux and Windows, plus MySQL 8.0 and MariaDB 10.11.

## License

MIT
