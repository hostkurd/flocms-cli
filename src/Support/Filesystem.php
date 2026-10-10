<?php
declare(strict_types=1);

namespace FloCMS\CLI\Support;

use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Project;

/**
 * File operations confined to the project directory.
 */
final class Filesystem
{
    public function __construct(private readonly Project $project)
    {
    }

    /**
     * Resolve a project-relative (or absolute) path and refuse anything that
     * ends up outside the project root, including through ".." segments,
     * absolute paths elsewhere and symlinked parents.
     */
    public function safePath(string $path): string
    {
        $root = self::normalize($this->project->root());
        $isAbsolute = preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) === 1;
        $target = self::normalize($isAbsolute ? $path : $root . '/' . $path);

        if (!self::inside($target, $root)) {
            throw new CommandFailedException('Refusing to use a path outside the project: ' . $path);
        }

        // A symlinked directory inside the project must not lead outside it.
        $realRoot = realpath($this->project->root());
        $existing = $target;
        while (!file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        $realExisting = realpath($existing);
        if ($realRoot !== false && $realExisting !== false && !self::inside(self::normalize($realExisting), self::normalize($realRoot))) {
            throw new CommandFailedException('Refusing to use a path outside the project: ' . $path);
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $target);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->safePath($path));
    }

    public function read(string $path): string
    {
        $file = $this->safePath($path);
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            throw new CommandFailedException('Cannot read ' . $this->project->relative($file) . '.');
        }

        return $contents;
    }

    /** Write a file, creating its directory. */
    public function write(string $path, string $contents): string
    {
        $file = $this->safePath($path);
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new CommandFailedException('Cannot create directory ' . $this->project->relative($directory) . '.');
        }
        if (@file_put_contents($file, $contents, LOCK_EX) === false) {
            throw new CommandFailedException('Cannot write ' . $this->project->relative($file) . '.');
        }

        return $file;
    }

    public function delete(string $path): bool
    {
        $file = $this->safePath($path);

        return is_file($file) && @unlink($file);
    }

    /** Remove a directory when it is empty. */
    public function deleteEmptyDirectory(string $path): void
    {
        $directory = $this->safePath($path);
        if (is_dir($directory) && (scandir($directory) ?: []) === ['.', '..'] && self::normalize($directory) !== self::normalize($this->project->root())) {
            @rmdir($directory);
        }
    }

    /** Lexically resolve "." and ".."; forward slashes. */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';
        if (preg_match('#^([A-Za-z]:)?/#', $path, $match) === 1) {
            $prefix = $match[0];
            $path = substr($path, strlen($prefix));
        } elseif (preg_match('#^[A-Za-z]:#', $path, $match) === 1) {
            $prefix = $match[0] . '/';
            $path = substr($path, 2);
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    // Above the filesystem root: keep it so the inside() check fails
                    $parts[] = '..';
                    continue;
                }
                if (end($parts) !== '..') {
                    array_pop($parts);
                    continue;
                }
            }
            $parts[] = $part;
        }

        return rtrim($prefix . implode('/', $parts), '/') ?: $prefix;
    }

    private static function inside(string $path, string $root): bool
    {
        $windows = DIRECTORY_SEPARATOR === '\\' || preg_match('#^[A-Za-z]:#', $root) === 1;
        $compare = static fn (string $p): string => $windows ? strtolower($p) : $p;
        $path = $compare(rtrim($path, '/'));
        $root = $compare(rtrim($root, '/'));

        return !str_contains($path, '/../') && !str_starts_with($path, '../') && ($path === $root || str_starts_with($path, $root . '/'));
    }
}
