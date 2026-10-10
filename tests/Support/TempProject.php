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

    /**
     * Write the skeleton's root launcher, using flocms-cli's own autoloader.
     */
    public function launcher(): string
    {
        $autoload = var_export(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', true);

        return $this->write('flo', "#!/usr/bin/env php\n<?php\nrequire {$autoload};\nexit(FloCMS\\CLI\\Kernel::handle(__DIR__, \$argv));\n");
    }

    /**
     * Run the project's flo launcher in a subprocess.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function flo(array $args, ?string $cwd = null, array $env = [], string $stdin = ''): array
    {
        if (!is_file($this->path('flo'))) {
            $this->launcher();
        }
        $relative = $cwd === null ? 'flo' : $this->relativeLauncher($cwd);
        $process = proc_open(
            [PHP_BINARY, $relative, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd ?? $this->root,
            $env === [] ? null : array_merge(getenv(), $env)
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start flo.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function relativeLauncher(string $cwd): string
    {
        $depth = substr_count(trim(substr($cwd, strlen($this->root)), '/\\'), DIRECTORY_SEPARATOR) + 1;

        return str_repeat('..' . DIRECTORY_SEPARATOR, $depth) . 'flo';
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
