<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Testing;

use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Project;

/**
 * Runs one command and captures STDOUT, STDERR and the exit code.
 *
 *     $tester = new CommandTester(new GreetCommand());
 *     $tester->execute(['World', '--shout']);           // tokens, as typed
 *     $tester->execute(['who' => 'World', '--shout' => true]); // or by name
 *     self::assertSame("Hello World!\n", $tester->getDisplay());
 *
 * Pass $inputs to answer prompts (ask, confirm, choice, secret) in order.
 */
final class CommandTester
{
    use CapturesOutput;

    private Application $application;

    public function __construct(private readonly Command $command, ?Project $project = null, ?Application $application = null)
    {
        if ($application === null) {
            $application = new Application('FloCMS CLI', '', $project ?? $command->getApplication()?->project());
            $application->add($command);
        } elseif (!$application->has($command->getName())) {
            $application->add($command);
        }
        $this->application = $application;
    }

    /**
     * @param array<int|string, mixed> $arguments tokens (list) or ['name' => value, '--option' => value]
     * @param list<string> $inputs answers to prompts, one per line
     * @param array{interactive?: bool, verbosity?: int, decorated?: bool} $options
     */
    public function execute(array $arguments = [], array $inputs = [], array $options = []): int
    {
        $output = $this->prepare($inputs, $options);
        $this->application->setOutput($output);
        $this->application->setInputStream($this->input);
        $this->application->setInteractive($options['interactive'] ?? true);

        return $this->statusCode = $this->application->runCommand($this->command, self::tokens($arguments), $output);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return list<string>
     */
    public static function tokens(array $arguments): array
    {
        if (array_is_list($arguments)) {
            return array_map('strval', $arguments);
        }

        $options = [];
        $positional = [];
        foreach ($arguments as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, '-')) {
                foreach ((array) $value as $item) {
                    if ($item === false || $item === null) {
                        continue;
                    }
                    $options[] = $item === true ? $key : $key . '=' . $item;
                }
                continue;
            }
            foreach ((array) $value as $item) {
                $positional[] = (string) $item;
            }
        }

        return $positional === [] ? $options : [...$options, '--', ...$positional];
    }
}
