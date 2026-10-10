<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Db;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Database\Seeder;
use FloCMS\CLI\Support\Name;

final class DbSeedCommand extends DatabaseCommand
{
    protected string $name = 'db:seed';

    protected string $description = 'Fill the database with data from database/seeders';

    protected array $examples = [
        'php flo db:seed                      # Database\Seeders\DatabaseSeeder',
        'php flo db:seed --class=PostsSeeder',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('class', 'c', Option::VALUE_REQUIRED, 'The seeder class', 'DatabaseSeeder')
            ->option('force', 'f', Option::VALUE_NONE, 'Run on production without asking');
    }

    protected function handle(): int
    {
        if (!$this->confirmInProduction('Seed the database of this production site?')) {
            $this->comment('Cancelled.');

            return self::FAILURE;
        }
        $this->runSeeder((string) $this->option('class'));
        $this->success('Database seeded.');

        return self::SUCCESS;
    }

    private function runSeeder(string $class): void
    {
        $seeder = $this->resolve($class);
        $seeder->setCaller(fn (string $other) => $this->runSeeder($other));
        $this->line('  <comment>Seeding</comment> ' . (new \ReflectionClass($seeder))->getShortName());
        $seeder->run($this->project()->database());
    }

    private function resolve(string $class): Seeder
    {
        $class = ltrim($class, '\\');
        $short = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;
        $fqcn = str_contains($class, '\\') ? $class : 'Database\\Seeders\\' . $class;
        if (!class_exists($fqcn)) {
            $file = $this->project()->path('database/seeders/' . Name::studly($short, 'seeder class') . '.php');
            if (!is_file($file)) {
                $this->fail('Seeder ' . $fqcn . ' not found (database/seeders/' . $short . '.php). Create it with "php flo make:seeder ' . $short . '".');
            }
            require_once $file;
        }
        if (!class_exists($fqcn) || !is_subclass_of($fqcn, Seeder::class)) {
            $this->fail($fqcn . ' must extend ' . Seeder::class . '.');
        }

        return new $fqcn();
    }
}
