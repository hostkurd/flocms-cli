<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\App;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\KeyGenerator;

final class KeyGenerateCommand extends Command
{
    protected string $name = 'key:generate';

    protected string $description = 'Set the application key (APP_KEY) in .env';

    protected string $help = <<<'TXT'
Creates a random 32-byte APP_KEY when .env has none. An existing key is kept
unless you pass --force: replacing it logs everyone out and makes data
encrypted with the old key unreadable.
TXT;

    protected array $examples = [
        'php flo key:generate',
        'php flo key:generate --show',
        'php flo key:generate --force',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('force', 'f', Option::VALUE_NONE, 'Replace an existing key')
            ->option('show', null, Option::VALUE_NONE, 'Print a new key without changing .env');
    }

    protected function handle(): int
    {
        if ($this->option('show')) {
            $this->line(KeyGenerator::generate());

            return self::SUCCESS;
        }

        $project = $this->project();
        $env = $project->path('.env');
        if (!is_file($env)) {
            $this->fail('No .env file in ' . $project->root() . '. Copy .env.example to .env first.');
        }

        if ($this->option('force') && KeyGenerator::hasKey($env) && $project->isProduction()
            && !$this->confirm('This is a production site. Replacing APP_KEY logs everyone out. Continue?', !$this->input->isInteractive())) {
            $this->comment('APP_KEY was not changed.');

            return self::FAILURE;
        }

        try {
            $key = KeyGenerator::writeToEnv($env, (bool) $this->option('force'));
        } catch (\RuntimeException $error) {
            $this->fail($error->getMessage());
        }
        $project->refresh();

        if ($key === null) {
            $this->line('APP_KEY is already set. Use --force to replace it.');

            return self::SUCCESS;
        }
        $this->success('APP_KEY set in .env.');

        return self::SUCCESS;
    }
}
