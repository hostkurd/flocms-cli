<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\App;

use FloCMS\CLI\Database\ConnectionFactory;
use FloCMS\CLI\Project;
use Throwable;

/**
 * The database side of maintenance mode: the offline_mode setting in the
 * settings table (param, value, lang) that flocms-core and the admin panel
 * use. Best effort: without a database the file flag alone applies.
 */
final class OfflineSetting
{
    /** @return array{changed: bool, warning: ?string} */
    public static function set(Project $project, bool $offline): array
    {
        if (!ConnectionFactory::isConfigured($project)) {
            return ['changed' => false, 'warning' => null];
        }
        try {
            $db = $project->database();
            $value = $offline ? '1' : '0';
            $changed = $db->query('UPDATE settings SET value = ? WHERE param = ? AND value <> ?', [$value, 'offline_mode', $value])->rowCount();
            $exists = (int) $db->query('SELECT COUNT(*) FROM settings WHERE param = ?', ['offline_mode'])->fetchColumn() > 0;
            if (!$exists && $offline) {
                $languages = array_filter(array_map('trim', explode(',', (string) $project->env('LANGUAGES', $project->env('DEFAULT_LANG', 'en')))));
                foreach ($languages ?: ['en'] as $language) {
                    $db->query('INSERT INTO settings (param, value, lang) VALUES (?, ?, ?)', ['offline_mode', '1', $language]);
                    $changed++;
                }
            }

            return ['changed' => $changed > 0, 'warning' => null];
        } catch (Throwable $error) {
            return ['changed' => false, 'warning' => 'The offline_mode setting was not changed (' . $error->getMessage() . '). The maintenance file applies anyway.'];
        }
    }
}
