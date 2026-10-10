<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Make;

final class MakeResourceCommand extends SimpleClassGenerator
{
    protected string $name = 'make:resource';

    protected string $description = 'Create an API resource (JsonResource) in api/Resources';

    protected array $examples = ['php flo make:resource Post'];

    protected string $stubName = 'resource';

    protected string $suffix = 'Resource';

    protected string $directory = 'api/Resources';
}
