<?php
declare(strict_types=1);

namespace FloCMS\CLI\Database;

use FloCMS\Core\Database;

/**
 * Base class for seeders in database/seeders (`php flo db:seed`).
 */
abstract class Seeder
{
    /** @var null|\Closure(string): void runs another seeder by class name */
    private ?\Closure $caller = null;

    abstract public function run(Database $db): void;

    /** Run other seeders, e.g. $this->call(UsersSeeder::class). */
    protected function call(string ...$classes): void
    {
        foreach ($classes as $class) {
            if ($this->caller === null) {
                throw new \LogicException('Seeders can only call other seeders when run by db:seed.');
            }
            ($this->caller)($class);
        }
    }

    /** @internal */
    public function setCaller(callable $caller): void
    {
        $this->caller = \Closure::fromCallable($caller);
    }
}
