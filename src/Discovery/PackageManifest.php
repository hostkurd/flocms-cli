<?php
declare(strict_types=1);

namespace FloCMS\CLI\Discovery;

/**
 * What installed packages (and the project itself) contribute to the CLI,
 * read from composer.json "extra":
 *
 *     "extra": {
 *         "flocms": {
 *             "commands": ["Vendor\\Package\\Console\\FooCommand"],
 *             "migrations": ["database/migrations"]
 *         }
 *     }
 *
 * Packages are read from vendor/composer/installed.json, which Composer
 * rewrites on every install and update.
 */
final class PackageManifest
{
    /** @var array<string, array{path: string, extra: array<string, mixed>}> package name => install path and flocms extra */
    private array $packages = [];

    public function __construct(private readonly string $projectRoot, ?string $vendorDir = null)
    {
        $vendorDir ??= $projectRoot . DIRECTORY_SEPARATOR . 'vendor';
        $this->readInstalled($vendorDir);
        $this->readRoot();
    }

    /** @return array<string, array{path: string, extra: array<string, mixed>}> */
    public function packages(): array
    {
        return $this->packages;
    }

    /**
     * Command classes, in package order (the project last).
     *
     * @return array<string, list<string>> package name => class names
     */
    public function commands(): array
    {
        return $this->listOf('commands');
    }

    /**
     * Migration directories as absolute paths.
     *
     * @return array<string, list<string>> package name => directories
     */
    public function migrations(): array
    {
        $directories = [];
        foreach ($this->listOf('migrations') as $package => $paths) {
            foreach ($paths as $path) {
                $directories[$package][] = $this->packages[$package]['path'] . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, trim(str_replace('\\', '/', $path), '/'));
            }
        }

        return $directories;
    }

    /**
     * Any list under extra.flocms.<key>.
     *
     * @return array<string, list<string>>
     */
    public function listOf(string $key): array
    {
        $values = [];
        foreach ($this->packages as $package => $info) {
            $list = $info['extra'][$key] ?? [];
            if (is_string($list)) {
                $list = [$list];
            }
            if (!is_array($list)) {
                continue;
            }
            $list = array_values(array_filter($list, 'is_string'));
            if ($list !== []) {
                $values[$package] = $list;
            }
        }

        return $values;
    }

    private function readInstalled(string $vendorDir): void
    {
        $file = $vendorDir . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            return;
        }
        // Composer 2: {"packages": [...]}; Composer 1: [...]
        $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['name']) || !is_string($package['name'])) {
                continue;
            }
            $extra = $package['extra']['flocms'] ?? null;
            if (!is_array($extra)) {
                continue;
            }
            $path = isset($package['install-path']) && is_string($package['install-path'])
                ? $vendorDir . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . $package['install-path']
                : $vendorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $package['name']);
            $this->packages[$package['name']] = ['path' => self::normalize($path), 'extra' => $extra];
        }
    }

    private function readRoot(): void
    {
        $file = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $extra = is_array($data) ? ($data['extra']['flocms'] ?? null) : null;
        if (!is_array($extra)) {
            return;
        }
        $name = is_string($data['name'] ?? null) ? $data['name'] : '__root__';
        $this->packages[$name] = ['path' => rtrim($this->projectRoot, '/\\'), 'extra' => $extra];
    }

    /** Resolve "." and ".." without touching the filesystem. */
    private static function normalize(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $path = str_replace('\\', '/', $path);
        $prefix = preg_match('#^([A-Za-z]:)?/#', $path, $m) === 1 ? $m[0] : '';
        $parts = [];
        foreach (explode('/', substr($path, strlen($prefix))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $prefix . implode('/', $parts));
    }
}
