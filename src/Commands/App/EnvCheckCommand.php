<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\App;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Project;

final class EnvCheckCommand extends Command
{
    protected string $name = 'env:check';

    protected string $description = 'Compare .env with .env.example and report missing keys';

    protected string $help = <<<'TXT'
After an upgrade, .env.example may list new settings. This shows keys that
are in .env.example but missing from .env (exit code 1), and keys only in .env.
TXT;

    protected function handle(): int
    {
        $project = $this->project();
        if (!is_file($project->path('.env.example'))) {
            $this->fail('No .env.example file to compare with.');
        }
        if (!$project->hasEnvFile()) {
            $this->fail('No .env file. Copy .env.example to .env first.');
        }
        $example = self::keys($project, '.env.example');
        $env = self::keys($project, '.env');

        $missing = array_values(array_diff($example, $env));
        $extra = array_values(array_diff($env, $example));
        foreach ($extra as $key) {
            $this->line('<muted>Only in .env:</muted> ' . $key);
        }
        if ($missing === []) {
            $this->success('.env has every key from .env.example (' . count($example) . ').');

            return self::SUCCESS;
        }
        $this->error(count($missing) . ' key(s) from .env.example are missing in .env:');
        foreach ($missing as $key) {
            $this->output->error('  ' . $key);
        }
        $this->output->error('Copy them from .env.example and set their values.');

        return self::FAILURE;
    }

    /** @return list<string> */
    public static function keys(Project $project, string $file): array
    {
        $keys = [];
        foreach (file($project->path($file), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            $key = trim(explode('=', $line, 2)[0]);
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }
}
