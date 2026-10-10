<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

final class MakeRequestCommand extends SimpleClassGenerator
{
    protected string $name = 'make:request';

    protected string $description = 'Create a request validation class (rules and messages) in api/Requests';

    protected array $examples = ['php flo make:request StorePost'];

    protected string $stubName = 'request';

    protected string $suffix = 'Request';

    protected string $directory = 'api/Requests';
}
