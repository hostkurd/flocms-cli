<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Lang;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Formatter;

final class LangMissingCommand extends Command
{
    protected string $name = 'lang:missing';

    protected string $description = 'Find translation keys missing between lang/en.php and the other languages';

    protected string $help = <<<'TXT'
Compares every language with the base language (en by default): keys missing
from a translation, and keys a translation has that the base does not. Also
checks LANGUAGES in .env against the files in lang/. Exit code 1 when keys or
files are missing.
TXT;

    protected array $examples = ['php flo lang:missing', 'php flo lang:missing --lang=ar'];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('lang', 'l', Option::VALUE_REQUIRED, 'Only this language')
            ->option('base', null, Option::VALUE_REQUIRED, 'The reference language', 'en')
            ->option('format', null, Option::VALUE_REQUIRED, 'txt or json', 'txt');
    }

    protected function handle(): int
    {
        $files = new LangFiles($this->project());
        $base = (string) $this->option('base');
        $reference = $files->load($base);

        $codes = $this->option('lang') !== null ? [(string) $this->option('lang')] : array_values(array_diff($files->codes(), [$base]));
        $report = [];
        $problems = 0;
        foreach ($codes as $code) {
            $translation = $files->load($code);
            $missing = array_values(array_diff(array_keys($reference), array_keys($translation)));
            $extra = array_values(array_diff(array_keys($translation), array_keys($reference)));
            $empty = array_values(array_keys(array_filter($translation, static fn ($v): bool => $v === '' || $v === null)));
            $report[$code] = ['missing' => $missing, 'extra' => $extra, 'empty' => $empty];
            $problems += count($missing);
        }

        $configured = $files->configured();
        $noFile = array_values(array_filter($configured, static fn (string $c): bool => !$files->exists($c)));
        $notConfigured = $configured === [] ? [] : array_values(array_diff($files->codes(), $configured));
        $problems += count($noFile);

        if ($this->option('format') === 'json') {
            $this->output->write(json_encode([
                'base' => $base,
                'languages' => $report,
                'configured_without_file' => $noFile,
                'files_not_configured' => $notConfigured,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

            return $problems === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report as $code => $result) {
            $this->section($code . ' (compared with ' . $base . ')');
            if ($result['missing'] === [] && $result['extra'] === [] && $result['empty'] === []) {
                $this->line('  <info>Complete</info> (' . count($reference) . ' keys)');
                continue;
            }
            foreach ($result['missing'] as $key) {
                $this->line('  <comment>missing</comment>  ' . Formatter::escape($key) . '  <muted>' . Formatter::escape(self::preview($reference[$key])) . '</muted>');
            }
            foreach ($result['empty'] as $key) {
                $this->line('  <comment>empty</comment>    ' . Formatter::escape($key));
            }
            foreach ($result['extra'] as $key) {
                $this->line('  <muted>extra</muted>    ' . Formatter::escape($key) . ' <muted>(not in ' . $base . ')</muted>');
            }
        }
        foreach ($noFile as $code) {
            $this->error('LANGUAGES lists "' . $code . '" but lang/' . $code . '.php does not exist. Create it with: php flo lang:add ' . $code);
        }
        foreach ($notConfigured as $code) {
            $this->note('lang/' . $code . '.php exists but "' . $code . '" is not in LANGUAGES.');
        }

        $this->newLine();
        if ($problems > 0) {
            $this->error($problems . ' missing translation key(s) or file(s).');

            return self::FAILURE;
        }
        $this->success('All translations have every key of ' . $base . '.');

        return self::SUCCESS;
    }

    private static function preview(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : json_encode($value);

        $text = (string) $text;
        if (!function_exists('mb_strlen')) {
            return strlen($text) > 50 ? substr($text, 0, 47) . '...' : $text;
        }

        return mb_strlen($text) > 50 ? mb_substr($text, 0, 47) . '...' : $text;
    }
}
