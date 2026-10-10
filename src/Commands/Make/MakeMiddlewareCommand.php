<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

final class MakeMiddlewareCommand extends SimpleClassGenerator
{
    protected string $name = 'make:middleware';

    protected string $description = 'Create an API middleware in api/Middleware';

    protected array $examples = ['php flo make:middleware RequireTenant'];

    protected string $stubName = 'middleware';

    protected string $directory = 'api/Middleware';
}
