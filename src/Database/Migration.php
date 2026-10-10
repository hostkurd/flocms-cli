<?php
declare(strict_types=1);

namespace FloCMS\CLI\Database;

use FloCMS\Core\Database;

/**
 * Base class for migrations in database/migrations. A migration file returns
 * an instance:
 *
 *     return new class extends Migration {
 *         public function up(Database $db): void { $db->query('CREATE TABLE ...'); }
 *         public function down(Database $db): void { $db->query('DROP TABLE ...'); }
 *     };
 *
 * The file name (without .php) is the migration's id; files run in name
 * order, so generated names start with a timestamp.
 */
abstract class Migration
{
    /**
     * Run up()/down() in a transaction. MySQL commits DDL (CREATE, ALTER,
     * DROP) immediately anyway; SQLite can roll it back.
     */
    public bool $transactional = true;

    abstract public function up(Database $db): void;

    public function down(Database $db): void
    {
        throw new \RuntimeException(static::class . ' cannot be rolled back (no down() method).');
    }

    /** An auto-increment primary key column for the current driver. */
    protected function id(Database $db, string $column = 'id'): string
    {
        return $db->driver() === 'sqlite'
            ? '`' . $column . '` INTEGER PRIMARY KEY AUTOINCREMENT'
            : '`' . $column . '` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    /** Table options for CREATE TABLE ("" on SQLite). */
    protected function tableOptions(Database $db): string
    {
        return $db->driver() === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
}
