<?php
declare(strict_types=1);

namespace FloCMS\CLI\Database;

use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Project;
use FloCMS\Core\Database;
use PDO;
use PDOException;

/**
 * Connects to the project's database from .env, like flocms-core's
 * App::db() does for the website:
 *
 *     DB_TYPE=mysql (default) DB_HOST DB_PORT DB_NAME DB_USERNAME DB_PASSWORD DB_CHARSET
 *     DB_TYPE=sqlite          DB_NAME=storage/database.sqlite (relative to the project)
 *     DB_DSN=...              any PDO DSN, with DB_USERNAME / DB_PASSWORD
 */
final class ConnectionFactory
{
    public static function make(Project $project): Database
    {
        return new Database(self::pdo($project));
    }

    public static function isConfigured(Project $project): bool
    {
        if ((string) $project->env('DB_DSN', '') !== '') {
            return true;
        }
        if (self::driver($project) === 'sqlite') {
            return (string) $project->env('DB_NAME', '') !== '';
        }

        return (string) $project->env('DB_NAME', '') !== '' && (string) $project->env('DB_USERNAME', '') !== '';
    }

    public static function driver(Project $project): string
    {
        $dsn = (string) $project->env('DB_DSN', '');
        if ($dsn !== '') {
            return strtolower(strstr($dsn, ':', true) ?: 'unknown');
        }

        return strtolower((string) ($project->env('DB_TYPE') ?? $project->env('DB_CONNECTION') ?? 'mysql'));
    }

    /**
     * Connection settings for messages (never the password).
     *
     * @return array<string, string|int|null>
     */
    public static function describe(Project $project): array
    {
        $driver = self::driver($project);
        if ((string) $project->env('DB_DSN', '') !== '') {
            return ['driver' => $driver, 'dsn' => (string) preg_replace('/password=[^;]*/i', 'password=***', (string) $project->env('DB_DSN'))];
        }
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => self::sqlitePath($project)];
        }

        return [
            'driver' => $driver,
            'host' => (string) $project->env('DB_HOST', 'localhost'),
            'port' => (int) $project->env('DB_PORT', 3306),
            'database' => (string) $project->env('DB_NAME', ''),
            'username' => (string) $project->env('DB_USERNAME', ''),
        ];
    }

    public static function pdo(Project $project): PDO
    {
        if (!self::isConfigured($project)) {
            throw new CommandFailedException('The database is not configured. Set DB_NAME and DB_USERNAME (and DB_PASSWORD) in .env, then run "php flo db:check".');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];
        $driver = self::driver($project);
        try {
            if ((string) $project->env('DB_DSN', '') !== '') {
                return new PDO((string) $project->env('DB_DSN'), self::nullable($project->env('DB_USERNAME')), self::nullable($project->env('DB_PASSWORD')), $options);
            }
            if ($driver === 'sqlite') {
                $path = self::sqlitePath($project);
                if ($path !== ':memory:' && !is_dir(dirname($path))) {
                    throw new CommandFailedException('The directory for the SQLite database does not exist: ' . dirname($path));
                }
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');

                return $pdo;
            }
            if ($driver !== 'mysql' && $driver !== 'mariadb') {
                throw new CommandFailedException('Unsupported DB_TYPE "' . $driver . '" (use mysql or sqlite, or set DB_DSN).');
            }

            return new PDO(
                'mysql:host=' . $project->env('DB_HOST', 'localhost')
                . ';port=' . (int) $project->env('DB_PORT', 3306)
                . ';dbname=' . $project->env('DB_NAME')
                . ';charset=' . $project->env('DB_CHARSET', 'utf8mb4'),
                (string) $project->env('DB_USERNAME'),
                (string) $project->env('DB_PASSWORD', ''),
                $options
            );
        } catch (PDOException $error) {
            throw new CommandFailedException(self::friendly($error, $project), 1, $error);
        }
    }

    private static function sqlitePath(Project $project): string
    {
        $name = (string) $project->env('DB_NAME', '');
        if ($name === ':memory:' || preg_match('#^([A-Za-z]:)?[/\\\\]#', $name) === 1) {
            return $name;
        }

        return $project->path($name);
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function friendly(PDOException $error, Project $project): string
    {
        $info = self::describe($project);
        $where = isset($info['host']) ? $info['host'] . ':' . $info['port'] . '/' . $info['database'] : (string) ($info['database'] ?? $info['dsn'] ?? '');
        $hint = match (true) {
            str_contains($error->getMessage(), '[2002]') => 'Is MySQL/MariaDB running (XAMPP: start MySQL) and are DB_HOST/DB_PORT right?',
            str_contains($error->getMessage(), '[1045]') => 'Check DB_USERNAME and DB_PASSWORD.',
            str_contains($error->getMessage(), '[1049]') => 'Create the database first (e.g. in phpMyAdmin) or fix DB_NAME.',
            default => 'Check the DB_* settings in .env.',
        };

        return 'Cannot connect to the database (' . $where . "): " . $error->getMessage() . "\n" . $hint;
    }
}
