<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Filesystem;
use FloCMS\CLI\Support\Stubs;

final class StubPublishCommand extends Command
{
    protected string $name = 'stub:publish';

    protected string $description = 'Copy the generator stubs to stubs/ so you can customise them';

    protected array $examples = [
        'php flo stub:publish',
        'php flo stub:publish controller model',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('stubs', Argument::IS_ARRAY, 'Only these stubs (e.g. controller model)')
            ->option('force', 'f', Option::VALUE_NONE, 'Overwrite stubs you already published');
    }

    protected function handle(): int
    {
        $available = Stubs::names();
        $wanted = $this->argument('stubs') ?: $available;
        $unknown = array_diff($wanted, $available);
        if ($unknown !== []) {
            $this->invalid('Unknown stub(s): ' . implode(', ', $unknown) . '. Available: ' . implode(', ', $available) . '.');
        }

        $files = new Filesystem($this->project());
        $published = 0;
        foreach ($wanted as $name) {
            $target = 'stubs/' . $name . '.stub';
            if ($files->exists($target) && !$this->option('force')) {
                $this->line('<muted>Kept</muted> ' . $target);
                continue;
            }
            $files->write($target, (string) file_get_contents(Stubs::packageDirectory() . DIRECTORY_SEPARATOR . $name . '.stub'));
            $this->line('<info>Published</info> ' . $target);
            $published++;
        }
        $this->success($published . ' stub(s) published. The make:* commands now use the files in stubs/.');

        return self::SUCCESS;
    }
}
