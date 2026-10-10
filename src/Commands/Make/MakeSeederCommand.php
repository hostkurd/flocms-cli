<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

final class MakeSeederCommand extends SimpleClassGenerator
{
    protected string $name = 'make:seeder';

    protected string $description = 'Create a database seeder in database/seeders';

    protected array $examples = ['php flo make:seeder Posts'];

    protected string $stubName = 'seeder';

    protected string $suffix = 'Seeder';

    protected string $directory = 'database/seeders';
}
