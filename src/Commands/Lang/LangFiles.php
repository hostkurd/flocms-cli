<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Lang;

use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Project;

/**
 * The project's translation files: lang/<code>.php returning a flat array
 * of dotted keys (flocms-core's Lang).
 */
final class LangFiles
{
    public function __construct(private readonly Project $project)
    {
    }

    /** @return list<string> language codes that have a file, sorted */
    public function codes(): array
    {
        $codes = [];
        foreach (glob($this->project->path('lang') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $codes[] = basename($file, '.php');
        }
        sort($codes);

        return $codes;
    }

    /** @return list<string> codes listed in LANGUAGES (.env) */
    public function configured(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->project->env('LANGUAGES', '')))));
    }

    public function exists(string $code): bool
    {
        return is_file($this->file($code));
    }

    public function file(string $code): string
    {
        return $this->project->path('lang/' . $code . '.php');
    }

    /** @return array<string, mixed> */
    public function load(string $code): array
    {
        if (!$this->exists($code)) {
            throw new CommandFailedException('No translation file lang/' . $code . '.php.');
        }
        $data = (static fn (string $__file): mixed => include $__file)($this->file($code));
        if (!is_array($data)) {
            throw new CommandFailedException('lang/' . $code . '.php must return an array.');
        }

        return $data;
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/D', $code) === 1;
    }

    /**
     * PHP source for a translation file, keeping the key order.
     *
     * @param array<string, mixed> $translations
     */
    public static function export(array $translations, string $comment = ''): string
    {
        $lines = ["<?php", ''];
        if ($comment !== '') {
            $lines[] = '// ' . $comment;
        }
        $lines[] = 'return [';
        foreach ($translations as $key => $value) {
            $lines[] = '    ' . var_export((string) $key, true) . ' => ' . var_export(is_scalar($value) || $value === null ? (string) $value : $value, true) . ',';
        }
        $lines[] = '];';

        return implode("\n", $lines) . "\n";
    }
}
