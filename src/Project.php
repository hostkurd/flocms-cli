<?php
declare(strict_types=1);

namespace FloCMS\CLI;

use FloCMS\CLI\Database\ConnectionFactory;
use FloCMS\Core\Database;
use FloCMS\Core\Env;
use RuntimeException;

/**
 * The FloCMS application the CLI works on.
 *
 * The root comes from the launcher's location (the project's `flo` file, or
 * vendor/bin/flo), never from the current directory, so `php ../flo ...`
 * from a subfolder still writes to the right place.
 */
class Project
{
    private readonly string $root;

    /** @var array<string, string>|null values from .env */
    private ?array $dotenv = null;

    private bool $booted = false;

    private ?Database $database = null;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    /**
     * The project for a launcher directory: the directory itself when it is
     * a FloCMS project, otherwise the nearest parent that is (e.g. for
     * vendor/hostkurd/flocms-cli/bin), otherwise the directory as given.
     */
    public static function fromLauncher(string $directory): self
    {
        $directory = realpath($directory) ?: $directory;
        if (self::isProjectRoot($directory)) {
            return new self($directory);
        }

        return new self(self::detect($directory) ?? $directory);
    }

    /**
     * Walk up from $start to the first directory whose composer.json
     * requires hostkurd/flocms-core (or is the FloCMS skeleton).
     */
    public static function detect(string $start): ?string
    {
        $directory = realpath($start) ?: $start;
        while (true) {
            if (self::isProjectRoot($directory)) {
                return $directory;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }
    }

    public static function isProjectRoot(string $directory): bool
    {
        $file = $directory . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($file)) {
            return false;
        }
        $composer = json_decode((string) file_get_contents($file), true);
        if (!is_array($composer)) {
            return false;
        }

        return ($composer['name'] ?? null) === 'hostkurd/flocms'
            || isset($composer['require']['hostkurd/flocms-core'])
            || isset($composer['require-dev']['hostkurd/flocms-core']);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Absolute path of a project-relative path (either separator works). */
    public function path(string $relative = ''): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        return $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /** A path relative to the root for messages ("models/BlogModel.php"). */
    public function relative(string $absolute): string
    {
        $root = str_replace('\\', '/', $this->root) . '/';
        $path = str_replace('\\', '/', $absolute);
        $matches = DIRECTORY_SEPARATOR === '\\' ? stripos($path, $root) === 0 : str_starts_with($path, $root);

        return $matches ? substr($path, strlen($root)) : $path;
    }

    public function vendorDir(): string
    {
        return $this->path('vendor');
    }

    public function hasEnvFile(): bool
    {
        return is_file($this->path('.env'));
    }

    /**
     * A setting from the real environment or the project's .env file, cast
     * like FloCMS\Core\Env ("true", "false", "null", numbers).
     */
    public function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            $value = $this->dotenv()[$key] ?? null;
        }
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => is_numeric($value) ? (str_contains($value, '.') ? (float) $value : (int) $value) : $value,
        };
    }

    public function environment(): string
    {
        return (string) ($this->env('APP_ENV') ?? 'production');
    }

    public function isProduction(): bool
    {
        return in_array(strtolower($this->environment()), ['production', 'prod', 'live'], true);
    }

    /**
     * The .env file as raw strings (same parsing rules as FloCMS\Core\Env).
     *
     * @return array<string, string>
     */
    public function dotenv(): array
    {
        if ($this->dotenv !== null) {
            return $this->dotenv;
        }
        $this->dotenv = [];
        $file = $this->path('.env');
        if (!is_file($file)) {
            return $this->dotenv;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $this->dotenv[trim($key)] = $value;
        }

        return $this->dotenv;
    }

    /** The project's database (see ConnectionFactory for the .env settings). */
    public function database(): Database
    {
        if ($this->database === null) {
            $this->database = ConnectionFactory::make($this);
            // Models used by commands (new UsersModel()) share this connection
            if (\FloCMS\Core\App::$db === null) {
                \FloCMS\Core\App::$db = $this->database;
            }
        }

        return $this->database;
    }

    /** Use this connection instead of the .env settings (tests, packages). */
    public function setDatabase(?Database $database): void
    {
        $this->database = $database;
    }

    /** Forget cached .env values (after a command changed the file). */
    public function refresh(): void
    {
        $this->dotenv = null;
    }

    /**
     * Boot the application like public/index.php does, for commands that
     * need its configuration (routes, permissions, models): define ROOT and
     * the path constants, load .env into the environment, then
     * includes/compat.php and config/config.php.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        if (defined('ROOT') && rtrim((string) constant('ROOT'), '/\\') !== $this->root) {
            throw new RuntimeException('Another project (' . constant('ROOT') . ') is already booted in this process.');
        }
        $constants = [
            'DS' => DIRECTORY_SEPARATOR,
            'ROOT' => $this->root,
            'VIEWS_PATH' => $this->path('views'),
            'TEMPLATES_PATH' => $this->path('templates'),
            'APP_VIEWS_PATH' => $this->path('views'),
            'APP_TEMPLATES_PATH' => $this->path('templates'),
            'APP_STORAGE_PATH' => $this->path('storage'),
        ];
        foreach ($constants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
        if ($this->hasEnvFile()) {
            Env::load($this->path('.env'));
        }
        foreach (['includes/compat.php', 'config/config.php'] as $file) {
            if (is_file($this->path($file))) {
                (static function (string $__file): void {
                    require_once $__file;
                })($this->path($file));
            }
        }
        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }
}
