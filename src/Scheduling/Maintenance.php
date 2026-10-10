<?php
declare(strict_types=1);

namespace FloCMS\CLI\Scheduling;

use FloCMS\CLI\Project;
use FloCMS\CLI\Support\Filesystem;

/**
 * Built-in housekeeping tasks.
 */
final class Maintenance
{
    /** @return int files deleted */
    public static function cleanDirectory(Project $project, string $directory, int $hours, ?int $now = null): int
    {
        $path = (new Filesystem($project))->safePath($directory);
        if (!is_dir($path)) {
            return 0;
        }
        $limit = ($now ?? time()) - $hours * 3600;
        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || $item->getFilename() === '.gitignore') {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } elseif ($item->getMTime() < $limit && @unlink($item->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * app.log -> app.log.1 -> app.log.2 ... (oldest beyond $keep removed).
     *
     * @return int files rotated
     */
    public static function rotateLogs(Project $project, int $maxBytes, int $keep): int
    {
        $rotated = 0;
        foreach (glob($project->path('storage/logs') . DIRECTORY_SEPARATOR . '*.log') ?: [] as $log) {
            clearstatcache(true, $log);
            if ((int) @filesize($log) <= $maxBytes) {
                continue;
            }
            @unlink($log . '.' . $keep);
            for ($i = $keep - 1; $i >= 1; $i--) {
                if (is_file($log . '.' . $i)) {
                    @rename($log . '.' . $i, $log . '.' . ($i + 1));
                }
            }
            if (@rename($log, $log . '.1')) {
                @touch($log);
                $rotated++;
            }
        }

        return $rotated;
    }
}
