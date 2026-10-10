# Upgrading

## "Your flo launcher is outdated"

The `flo` file in your project root is copied once by
`composer create-project`. `composer update` never changes it, so a site
created with an older FloCMS keeps its old launcher. With flocms-cli 1.x, old
launchers send every command to the 1.x CLI, which only knows `create:*` and
`delete:*`, so newer commands such as `php flo api:key:create` fail with:

    Command "api:key:create" not found.
    Your flo launcher is outdated ...

Upgrade to flocms-cli 2.0 as described below. Until then, API commands from
`hostkurd/flocms-api` can run directly:

    php vendor/bin/flo-api api:list
    php vendor/bin/flo-api api:key:create "Mobile app" --scopes=properties.read

## 1.x to 2.0

flocms-cli 2.0 needs PHP 8.1 and hostkurd/flocms-core 2.2 (FloCMS 1.5+).

### 1. Require the new version

In your project's `composer.json`:

```json
"require": {
    "hostkurd/flocms-cli": "2.0.0",
    "hostkurd/flocms-api": "1.2.0"
}
```

(`hostkurd/flocms-api` only if you use the API; 1.2 registers its `api:*`
commands with the new CLI.) Add the autoload entry for your own commands:

```json
"autoload": {
    "psr-4": {
        "App\\Commands\\": "commands/"
    }
}
```

Then run `composer update`.

### 2. Replace the root `flo` file (once)

Replace the `flo` file in your project root with:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';
exit(FloCMS\CLI\Kernel::handle(__DIR__, $argv));
```

This is the last time you need to touch it: all logic now lives in `vendor/`,
so `composer update` brings new commands and fixes. (If you skip this step,
your old launcher still works for most commands, because it calls
`vendor/bin/flo`, which boots the same kernel. Launchers from FloCMS 1.5/1.6
keep handling `key:generate` and `api:*` themselves.)

FloCMS 1.6's `support/KeyGenerator.php` is no longer used and can be deleted.
`php flo key:generate` now comes from the CLI.

### 3. Check what changed

| 1.x | 2.0 |
|---|---|
| `php flo --v` | `php flo -V` (real versions from Composer) |
| `php flo --help` | `php flo` / `php flo list` (every command), `php flo help <command>` |
| `create:controller`, `create:model`, `create:view`, `create:route` | Still work; aliases of `make:*` |
| `delete:*` deleted without asking | Lists the files and asks; `--force` to skip, `--dry-run` to preview |
| Generated actions `Index()`, model created in the constructor | `index(): void`, lazy `model()` accessor |
| Exit code 0 on errors, errors on STDOUT | 0 / 1 / 2, errors on STDERR |
| Paths from the current directory | Paths from the project root |

Code that used the 1.x classes (`FloCMS\CLI\Gate`, `Core`, `Help`, `Colors`)
must move to the new API (`FloCMS\CLI\Kernel`, `FloCMS\CLI\Console\*`).

### 4. Optional next steps

- `php flo doctor` checks the installation.
- `php flo migrate` creates the `migrations` table. The flocms-api schema is
  a migration too: sites that already ran `api:install-schema` just get it
  recorded.
- Add the scheduler to cron: `* * * * * php /path/to/site/flo schedule:run`.
- `php flo completion bash >> ~/.bashrc` for tab completion.
