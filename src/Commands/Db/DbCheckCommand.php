<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Db;

use FloCMS\CLI\Database\ConnectionFactory;

final class DbCheckCommand extends DatabaseCommand
{
    protected string $name = 'db:check';

    protected string $description = 'Check that the database is configured and reachable';

    protected function handle(): int
    {
        $project = $this->project();
        if (!ConnectionFactory::isConfigured($project)) {
            $this->fail('The database is not configured. Set DB_NAME and DB_USERNAME (and DB_PASSWORD) in .env.');
        }
        $db = $project->database();
        $db->query('SELECT 1');
        $info = ConnectionFactory::describe($project);
        $this->success('Connected to ' . ($info['database'] ?? $info['dsn'] ?? '') . ' (' . $db->driver() . ', ' . self::serverVersion($db) . ').');

        return self::SUCCESS;
    }

    public static function serverVersion(\FloCMS\Core\Database $db): string
    {
        try {
            return (string) $db->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            return 'unknown version';
        }
    }
}
