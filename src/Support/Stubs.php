<?php
declare(strict_types=1);

namespace FloCMS\CLI\Support;

use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Project;

/**
 * Code templates for the make:* commands. A project can override any stub
 * by putting a file with the same name in its stubs/ folder
 * (`php flo stub:publish` copies them there).
 *
 * Placeholders: {{ name }}, written exactly like that (spaces optional).
 */
final class Stubs
{
    public function __construct(private readonly ?Project $project = null)
    {
    }

    public static function packageDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'stubs';
    }

    /** @return list<string> stub names shipped with flocms-cli */
    public static function names(): array
    {
        $names = [];
        foreach (glob(self::packageDirectory() . DIRECTORY_SEPARATOR . '*.stub') ?: [] as $file) {
            $names[] = basename($file, '.stub');
        }
        sort($names);

        return $names;
    }

    /** The file used for a stub: the project's override, or the package's. */
    public function path(string $name): string
    {
        if ($this->project !== null) {
            $override = $this->project->path('stubs/' . $name . '.stub');
            if (is_file($override)) {
                return $override;
            }
        }
        $file = self::packageDirectory() . DIRECTORY_SEPARATOR . $name . '.stub';
        if (!is_file($file)) {
            throw new CommandFailedException('Stub "' . $name . '" not found.');
        }

        return $file;
    }

    public function isOverridden(string $name): bool
    {
        return $this->project !== null && is_file($this->project->path('stubs/' . $name . '.stub'));
    }

    /** @param array<string, string> $replacements */
    public function render(string $name, array $replacements = []): string
    {
        $contents = str_replace("\r\n", "\n", (string) file_get_contents($this->path($name)));

        return self::fill($contents, $replacements);
    }

    /** @param array<string, string> $replacements */
    public static function fill(string $contents, array $replacements): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', static function (array $m) use ($replacements): string {
            return array_key_exists($m[1], $replacements) ? $replacements[$m[1]] : $m[0];
        }, $contents);
    }
}
