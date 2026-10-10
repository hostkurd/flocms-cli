<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Cache;

use FloCMS\CLI\Project;
use FloCMS\Core\Config;
use FloCMS\Core\TemplateEngine;

/**
 * The cache directories FloCMS writes to.
 */
final class CacheFiles
{
    /** Files in storage/cache kept by cache:clear unless --all (module registry and state). */
    public const KEEP = ['modules.php', 'modules-state.json'];

    public function __construct(private readonly Project $project)
    {
    }

    /**
     * Delete the contents of storage/cache (rate limits, login throttle,
     * idempotency records, ...), keeping .gitignore and, unless $all, the
     * module caches.
     *
     * @return int files removed
     */
    public function clearStorageCache(bool $all = false): int
    {
        $keep = $all ? ['.gitignore'] : ['.gitignore', ...self::KEEP];

        return self::clearDirectory($this->project->path('storage/cache'), $keep);
    }

    /** @return int compiled templates removed */
    public function clearViews(): int
    {
        return self::clearDirectory($this->project->path('views/cache'), ['.gitignore', '.gitkeep']);
    }

    /**
     * Compile every view and template into views/cache.
     *
     * @return array{compiled: int, failed: list<string>}
     */
    public function warmViews(): array
    {
        $cache = $this->project->path('views/cache');
        $previous = Config::get('view.cache_path');
        Config::set('view.cache_path', $cache);
        $compiled = 0;
        $failed = [];
        try {
            foreach ($this->templates() as $template) {
                if (TemplateEngine::compiledPath($template) !== null) {
                    $compiled++;
                } else {
                    $failed[] = $this->project->relative($template);
                }
            }
        } finally {
            Config::set('view.cache_path', $previous);
        }

        return ['compiled' => $compiled, 'failed' => $failed];
    }

    /** @return list<string> *.html files in views/ (not views/cache) and templates/ */
    public function templates(): array
    {
        $files = [];
        foreach (['views', 'templates'] as $directory) {
            $root = $this->project->path($directory);
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'html') {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($directory === 'views' && str_starts_with($relative, 'cache/')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @param list<string> $keep file names to keep at the top level */
    private static function clearDirectory(string $directory, array $keep): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        $removed = 0;
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
            if (in_array($item, $keep, true)) {
                continue;
            }
            $removed += self::remove($directory . DIRECTORY_SEPARATOR . $item);
        }

        return $removed;
    }

    private static function remove(string $path): int
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path) ? 1 : 0;
        }
        $removed = 0;
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $removed += self::remove($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);

        return $removed;
    }
}
