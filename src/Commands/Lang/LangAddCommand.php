<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Lang;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Filesystem;

final class LangAddCommand extends Command
{
    protected string $name = 'lang:add';

    protected string $description = 'Create lang/<locale>.php with the keys of lang/en.php and empty values';

    protected array $examples = ['php flo lang:add fr', 'php flo lang:add fa --rtl'];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('locale', Argument::REQUIRED, 'The language code, e.g. fr, de, pt_BR')
            ->option('base', null, Option::VALUE_REQUIRED, 'Copy the keys of this language', 'en')
            ->option('rtl', null, Option::VALUE_NONE, 'A right-to-left language (lng.dir = rtl)')
            ->option('force', 'f', Option::VALUE_NONE, 'Replace an existing file');
    }

    protected function handle(): int
    {
        $locale = (string) $this->argument('locale');
        if (!LangFiles::isValidCode($locale)) {
            $this->invalid('Invalid language code "' . $locale . '" (use e.g. fr, de or pt_BR).');
        }
        $files = new LangFiles($this->project());
        if ($files->exists($locale) && !$this->option('force')) {
            $this->fail('lang/' . $locale . '.php already exists (use --force to replace it).');
        }

        $keys = $files->load((string) $this->option('base'));
        $translations = [];
        foreach ($keys as $key => $value) {
            $translations[$key] = match ((string) $key) {
                'lng.dir' => $this->option('rtl') ? 'rtl' : 'ltr',
                default => '',
            };
        }
        (new Filesystem($this->project()))->write('lang/' . $locale . '.php', LangFiles::export($translations, 'Translations for "' . $locale . '", created from lang/' . $this->option('base') . '.php. Fill in the empty values.'));

        $this->success('Created lang/' . $locale . '.php with ' . count($translations) . ' keys to translate.');
        if (!in_array($locale, $files->configured(), true)) {
            $this->note('Add "' . $locale . '" to LANGUAGES in .env to enable it.');
        }

        return self::SUCCESS;
    }
}
