<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Support;

/**
 * A throwaway FloCMS project directory for tests.
 */
final class TempProject
{
    public readonly string $root;

    public function __construct()
    {
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->root = $base . DIRECTORY_SEPARATOR . 'flocms-cli-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->write('composer.json', json_encode([
            'name' => 'test/site',
            'require' => ['hostkurd/flocms-core' => '2.2.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function write(string $relative, string $contents): string
    {
        $file = $this->path($relative);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);

        return $file;
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    /** @param array<int, array<string, mixed>> $packages */
    public function installed(array $packages): void
    {
        $this->write('vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function remove(): void
    {
        self::delete($this->root);
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0666);
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $item) {
            self::delete($path . DIRECTORY_SEPARATOR . $item);
        }
        rmdir($path);
    }
}
