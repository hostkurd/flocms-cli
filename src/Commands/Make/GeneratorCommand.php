<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Support\Filesystem;
use FloCMS\CLI\Support\Stubs;

/**
 * Base for the make:* commands: --force, --dry-run, stubs and safe writes.
 */
abstract class GeneratorCommand extends Command
{
    /** @var list<string> files that already existed and were not overwritten */
    protected array $skipped = [];

    /** @var list<string> files written (or, with --dry-run, that would be) */
    protected array $written = [];

    final protected function configure(Definition $definition): void
    {
        $this->arguments($definition);
        $definition
            ->option('force', 'f', Option::VALUE_NONE, 'Overwrite existing files')
            ->option('dry-run', null, Option::VALUE_NONE, 'Show what would be written, without writing (-v shows the contents)');
    }

    /** Declare the generator's own arguments and options. */
    abstract protected function arguments(Definition $definition): void;

    final protected function handle(): int
    {
        $this->skipped = [];
        $this->written = [];
        $code = $this->generate();
        if ($code !== self::SUCCESS) {
            return $code;
        }

        return $this->skipped !== [] && $this->written === [] ? self::FAILURE : self::SUCCESS;
    }

    abstract protected function generate(): int;

    protected function files(): Filesystem
    {
        return new Filesystem($this->project());
    }

    /** @param array<string, string> $replacements */
    protected function stub(string $name, array $replacements = []): string
    {
        return (new Stubs($this->project()))->render($name, $replacements);
    }

    protected function isDryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    /** Create a new file; an existing one is only replaced with --force. */
    protected function create(string $relative, string $contents): bool
    {
        $files = $this->files();
        $exists = $files->exists($relative);
        if ($exists && !$this->option('force')) {
            $this->skipped[] = $relative;
            $this->warn(Formatter::escape($relative) . ' already exists (use --force to overwrite).');

            return false;
        }

        return $this->put($relative, $contents, $exists ? 'Overwrote' : 'Created');
    }

    /** Change an existing file (e.g. add a controller action). */
    protected function update(string $relative, string $contents, string $what = 'Updated'): bool
    {
        return $this->put($relative, $contents, $what);
    }

    private function put(string $relative, string $contents, string $verb): bool
    {
        $files = $this->files();
        $path = $files->safePath($relative);
        if ($this->isDryRun()) {
            $verb = match ($verb) {
                'Created' => 'Would create',
                'Overwrote' => 'Would overwrite',
                default => 'Would update',
            };
            $this->line('<comment>' . $verb . '</comment> ' . Formatter::escape($relative));
            foreach (explode("\n", rtrim($contents)) as $line) {
                $this->line('    <muted>' . Formatter::escape($line) . '</muted>', Output::VERBOSE);
            }
        } else {
            $files->write($relative, $contents);
            $this->line('<info>' . $verb . '</info> ' . Formatter::escape($this->project()->relative($path)));
        }
        $this->written[] = $relative;

        return true;
    }

    /** Options to pass on when calling another generator. */
    protected function passOptions(): array
    {
        $options = [];
        foreach (['force', 'dry-run'] as $option) {
            if ($this->option($option)) {
                $options[] = '--' . $option;
            }
        }
        if ($this->output->isVerbose()) {
            $options[] = '-v';
        }

        return $options;
    }

    /** "BlogPost" -> "Blog Post" */
    protected static function humanize(string $studly): string
    {
        return trim((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $studly));
    }

    /** A naive English plural for table and route names. */
    protected static function plural(string $word): string
    {
        return match (true) {
            preg_match('/[^aeiou]y$/i', $word) === 1 => substr($word, 0, -1) . 'ies',
            preg_match('/(ss|x|z|ch|sh)$/i', $word) === 1 => $word . 'es',
            preg_match('/s$/i', $word) === 1 => $word,
            default => $word . 's',
        };
    }
}
