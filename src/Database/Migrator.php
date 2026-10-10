<?php
declare(strict_types=1);

namespace FloCMS\CLI\Database;

use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Discovery\PackageManifest;
use FloCMS\CLI\Project;
use FloCMS\Core\Database;
use PDO;
use Throwable;

/**
 * Runs the migrations in database/migrations and those published by
 * packages (composer.json "extra": {"flocms": {"migrations": ["dir"]}}).
 *
 * Applied migrations are recorded in the `migrations` table with their
 * package, batch number and a checksum. Works on MySQL/MariaDB and SQLite.
 * Same file formats as core's ModuleMigrationRunner, plus down():
 *
 *     return new class extends Migration { up(Database $db) ... down(Database $db) ... };
 *     return ['up' => fn (Database $db) => ..., 'down' => [...SQL...], 'transactional' => false];
 */
final class Migrator
{
    public const APP = 'app';

    private bool $tableReady = false;

    /** @var null|\Closure(string, string): void progress callback (event, migration) */
    private ?\Closure $listener = null;

    public function __construct(
        private readonly Project $project,
        private readonly Database $db,
        private readonly string $table = 'migrations'
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            throw new \InvalidArgumentException('Invalid migrations table name "' . $table . '".');
        }
    }

    public static function forProject(Project $project): self
    {
        return new self($project, $project->database(), (string) $project->env('DB_MIGRATIONS_TABLE', 'migrations'));
    }

    /** @param callable(string, string): void $listener receives ('running'|'done'|'rolling back'|'rolled back', key) */
    public function onProgress(callable $listener): void
    {
        $this->listener = \Closure::fromCallable($listener);
    }

    /**
     * Migration directories by package; the application ("app") last.
     *
     * @return array<string, list<string>>
     */
    public function directories(): array
    {
        $directories = (new PackageManifest($this->project->root(), $this->project->vendorDir()))->migrations();
        $directories[self::APP] = [$this->project->path('database/migrations')];

        return $directories;
    }

    /**
     * Every migration file, in run order (file name, then package).
     *
     * @return array<string, array{key: string, package: string, name: string, file: string}> key "package:name"
     */
    public function files(?string $package = null): array
    {
        $files = [];
        foreach ($this->directories() as $owner => $directories) {
            if ($package !== null && $owner !== $package) {
                continue;
            }
            foreach ($directories as $directory) {
                foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                    $name = basename($file, '.php');
                    $files[$owner . ':' . $name] = ['key' => $owner . ':' . $name, 'package' => $owner, 'name' => $name, 'file' => $file];
                }
            }
        }
        uasort($files, static fn (array $a, array $b): int => [$a['name'], $a['package']] <=> [$b['name'], $b['package']]);

        return $files;
    }

    /**
     * Applied migrations from the table.
     *
     * @return array<string, array{package: string, migration: string, batch: int, checksum: string, executed_at: string}>
     */
    public function applied(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $rows = [];
        foreach ($this->db->query('SELECT package, migration, batch, checksum, executed_at FROM ' . $this->quotedTable() . ' ORDER BY batch, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['package'] . ':' . $row['migration']] = [
                'package' => (string) $row['package'],
                'migration' => (string) $row['migration'],
                'batch' => (int) $row['batch'],
                'checksum' => (string) $row['checksum'],
                'executed_at' => (string) $row['executed_at'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, package: string, name: string, ran: bool, batch: ?int, modified: bool, missing: bool}>
     */
    public function status(?string $package = null): array
    {
        $files = $this->files($package);
        $applied = $this->applied();
        $rows = [];
        foreach ($files as $key => $file) {
            $record = $applied[$key] ?? null;
            $rows[] = [
                'key' => $key,
                'package' => $file['package'],
                'name' => $file['name'],
                'ran' => $record !== null,
                'batch' => $record['batch'] ?? null,
                'modified' => $record !== null && $record['checksum'] !== self::checksum($file['file']),
                'missing' => false,
            ];
        }
        foreach ($applied as $key => $record) {
            if (!isset($files[$key]) && ($package === null || $record['package'] === $package)) {
                $rows[] = ['key' => $key, 'package' => $record['package'], 'name' => $record['migration'], 'ran' => true, 'batch' => $record['batch'], 'modified' => false, 'missing' => true];
            }
        }

        return $rows;
    }

    /** @return list<string> keys of migrations that have not run */
    public function pending(?string $package = null): array
    {
        return array_values(array_diff(array_keys($this->files($package)), array_keys($this->applied())));
    }

    /**
     * Run the pending migrations as one new batch.
     *
     * @return list<string> the keys that ran
     */
    public function migrate(?string $package = null, bool $pretend = false): array
    {
        return $this->locked(function () use ($package, $pretend): array {
            $this->ensureTable();
            $pending = $this->pending($package);
            if ($pending === [] || $pretend) {
                return $pending;
            }
            $files = $this->files($package);
            $batch = $this->lastBatch() + 1;
            $ran = [];
            foreach ($pending as $key) {
                $file = $files[$key];
                $this->notify('running', $key);
                $this->execute($this->load($file['file']), 'up', $key);
                $this->db->query(
                    'INSERT INTO ' . $this->quotedTable() . ' (package, migration, batch, checksum, executed_at) VALUES (?, ?, ?, ?, ?)',
                    [$file['package'], $file['name'], $batch, self::checksum($file['file']), date('Y-m-d H:i:s')]
                );
                $this->notify('done', $key);
                $ran[] = $key;
            }

            return $ran;
        });
    }

    /**
     * Roll back the last batch, or the last $steps migrations.
     *
     * @return list<string> the keys rolled back
     */
    public function rollback(?int $steps = null, ?string $package = null, bool $pretend = false): array
    {
        return $this->locked(function () use ($steps, $package, $pretend): array {
            $applied = $this->applied();
            if ($package !== null) {
                $applied = array_filter($applied, static fn (array $r): bool => $r['package'] === $package);
            }
            if ($applied === []) {
                return [];
            }
            $applied = array_reverse($applied, true);
            if ($steps === null) {
                $last = max(array_column($applied, 'batch'));
                $targets = array_keys(array_filter($applied, static fn (array $r): bool => $r['batch'] === $last));
            } else {
                $targets = array_slice(array_keys($applied), 0, max(1, $steps));
            }
            if ($pretend) {
                return $targets;
            }
            $files = $this->files();
            foreach ($targets as $key) {
                if (!isset($files[$key])) {
                    throw new CommandFailedException('Cannot roll back ' . $key . ': its migration file is missing.');
                }
            }
            foreach ($targets as $key) {
                $this->notify('rolling back', $key);
                $this->execute($this->load($files[$key]['file']), 'down', $key);
                $this->db->query('DELETE FROM ' . $this->quotedTable() . ' WHERE package = ? AND migration = ?', [$applied[$key]['package'], $applied[$key]['migration']]);
                $this->notify('rolled back', $key);
            }

            return $targets;
        });
    }

    /**
     * Drop every table (and view) of the database.
     *
     * @return list<string> the dropped tables
     */
    public function dropAllTables(): array
    {
        $pdo = $this->db->pdo();
        $dropped = [];
        if ($this->db->driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            foreach ($pdo->query("SELECT type, name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pdo->exec('DROP ' . strtoupper((string) $row['type']) . ' IF EXISTS "' . str_replace('"', '""', (string) $row['name']) . '"');
                $dropped[] = (string) $row['name'];
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
                    $kind = strtoupper((string) $row[1]) === 'VIEW' ? 'VIEW' : 'TABLE';
                    $pdo->exec('DROP ' . $kind . ' IF EXISTS `' . str_replace('`', '``', (string) $row[0]) . '`');
                    $dropped[] = (string) $row[0];
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $this->tableReady = false;

        return $dropped;
    }

    /**
     * Table names with row counts.
     *
     * @return list<array{table: string, rows: int}>
     */
    public function tables(): array
    {
        $pdo = $this->db->pdo();
        $names = $this->db->driver() === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        sort($names);
        $tables = [];
        foreach ($names as $name) {
            $quoted = $this->db->driver() === 'sqlite' ? '"' . str_replace('"', '""', (string) $name) . '"' : '`' . str_replace('`', '``', (string) $name) . '`';
            $tables[] = ['table' => (string) $name, 'rows' => (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn()];
        }

        return $tables;
    }

    public function tableExists(?string $table = null): bool
    {
        $table ??= $this->table;
        if ($this->db->driver() === 'sqlite') {
            return (bool) $this->db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->fetchColumn();
        }

        return (bool) $this->db->query('SHOW TABLES LIKE ' . $this->db->pdo()->quote(addcslashes($table, '%_')))->fetchColumn();
    }

    public function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }
        if ($this->db->driver() === 'sqlite') {
            $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS ' . $this->quotedTable() . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                package VARCHAR(190) NOT NULL,
                migration VARCHAR(190) NOT NULL,
                batch INTEGER NOT NULL,
                checksum CHAR(64) NOT NULL,
                executed_at DATETIME NOT NULL,
                UNIQUE (package, migration)
            )');
        } else {
            $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS ' . $this->quotedTable() . ' (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                package VARCHAR(190) NOT NULL,
                migration VARCHAR(190) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                checksum CHAR(64) NOT NULL,
                executed_at DATETIME NOT NULL,
                UNIQUE KEY migrations_package_migration (package, migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        $this->tableReady = true;
    }

    public function lastBatch(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        return (int) $this->db->query('SELECT MAX(batch) FROM ' . $this->quotedTable())->fetchColumn();
    }

    public static function checksum(string $file): string
    {
        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file)));
    }

    /**
     * @return array{up: callable(Database): void, down: null|callable(Database): void, transactional: bool}
     */
    public function load(string $file): array
    {
        $definition = (static function (string $__file): mixed {
            return require $__file;
        })($file);

        if (is_object($definition) && method_exists($definition, 'up')) {
            $transactional = property_exists($definition, 'transactional') ? (bool) $definition->transactional : true;

            return [
                'up' => static fn (Database $db) => $definition->up($db),
                'down' => method_exists($definition, 'down') ? static fn (Database $db) => $definition->down($db) : null,
                'transactional' => $transactional,
            ];
        }
        if (is_array($definition) && isset($definition['up'])) {
            return [
                'up' => self::callable($definition['up'], $file),
                'down' => isset($definition['down']) ? self::callable($definition['down'], $file) : null,
                'transactional' => (bool) ($definition['transactional'] ?? true),
            ];
        }

        throw new CommandFailedException(basename($file) . ' must return a Migration object or an array with "up" (and "down").');
    }

    /**
     * @param callable|list<string> $step
     * @return callable(Database): void
     */
    private static function callable(mixed $step, string $file): callable
    {
        if (is_callable($step)) {
            return $step;
        }
        if (is_array($step) && array_filter($step, 'is_string') === $step) {
            return static function (Database $db) use ($step): void {
                foreach ($step as $sql) {
                    $db->pdo()->exec($sql);
                }
            };
        }

        throw new CommandFailedException(basename($file) . ': "up" and "down" must be callables or lists of SQL statements.');
    }

    /** @param array{up: callable, down: null|callable, transactional: bool} $migration */
    private function execute(array $migration, string $direction, string $key): void
    {
        $step = $migration[$direction] ?? null;
        if ($step === null) {
            throw new CommandFailedException('Cannot roll back ' . $key . ': it has no down().');
        }
        $pdo = $this->db->pdo();
        $transaction = $migration['transactional'] && !$pdo->inTransaction();
        if ($transaction) {
            $pdo->beginTransaction();
        }
        try {
            $step($this->db);
            // MySQL commits DDL implicitly; only commit what is still open
            if ($transaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($transaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new CommandFailedException(($direction === 'up' ? 'Migration ' : 'Rollback of ') . $key . ' failed: ' . $error->getMessage(), 1, $error);
        }
    }

    /**
     * Only one migrate/rollback at a time: a file lock in storage/framework,
     * plus GET_LOCK on MySQL (several servers sharing one database).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $handle = null;
        $directory = $this->project->path('storage/framework');
        if (is_dir($directory) || @mkdir($directory, 0775, true)) {
            $handle = @fopen($directory . DIRECTORY_SEPARATOR . 'migrations.lock', 'c');
            if ($handle !== false && !flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                throw new CommandFailedException('Another migration is running (storage/framework/migrations.lock).');
            }
        }
        $mysqlLock = $this->db->driver() === 'mysql';
        if ($mysqlLock && (int) $this->db->query('SELECT GET_LOCK(?, 10)', ['flocms_migrations'])->fetchColumn() !== 1) {
            throw new CommandFailedException('Another migration is running on this database.');
        }
        try {
            return $work();
        } finally {
            if ($mysqlLock) {
                $this->db->query('SELECT RELEASE_LOCK(?)', ['flocms_migrations']);
            }
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function notify(string $event, string $key): void
    {
        if ($this->listener !== null) {
            ($this->listener)($event, $key);
        }
    }

    private function quotedTable(): string
    {
        return $this->db->driver() === 'sqlite' ? '"' . $this->table . '"' : '`' . $this->table . '`';
    }
}
