<?php
declare(strict_types=1);

namespace FloCMS\CLI;

use FloCMS\CLI\Commands\Legacy\LegacyCommand;
use FloCMS\CLI\Commands\HelpCommand;
use FloCMS\CLI\Commands\ListCommand;
use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Discovery\CommandLoader;
use FloCMS\CLI\Discovery\PackageManifest;

/**
 * Boots the command kernel for a FloCMS project. The project's root `flo`
 * launcher is only:
 *
 *     require __DIR__ . '/vendor/autoload.php';
 *     exit(FloCMS\CLI\Kernel::handle(__DIR__, $argv));
 *
 * Commands are registered in this order (a later one with the same name wins):
 * 1. built-in commands,
 * 2. package commands (composer.json "extra": {"flocms": {"commands": [...]}}),
 * 3. application commands in <root>/commands (namespace App\Commands).
 */
final class Kernel
{
    /** @var list<string> */
    private static array $warnings = [];

    /**
     * @param resource|null $stdin
     */
    public static function boot(string $root, ?Output $output = null, $stdin = null): Application
    {
        $project = Project::fromLauncher($root);
        $application = new Application('FloCMS CLI', '', $project, $output, $stdin);

        foreach (self::builtinCommands() as $command) {
            $application->add($command);
        }

        $loader = new CommandLoader();
        foreach ((new PackageManifest($project->root(), $project->vendorDir()))->commands() as $package => $classes) {
            foreach ($loader->fromClasses($classes, $package) as $command) {
                $application->add($command);
            }
        }
        foreach ($loader->fromDirectory($project->path('commands')) as $command) {
            $application->add($command);
        }
        self::$warnings = $loader->warnings();

        return $application;
    }

    /**
     * Boot and run; $argv includes the script name.
     *
     * @param list<string> $argv
     */
    public static function handle(string $root, array $argv): int
    {
        return self::boot($root)->run(array_values(array_slice($argv, 1)));
    }

    /** Discovery problems of the last boot (broken package or app commands). */
    public static function warnings(): array
    {
        return self::$warnings;
    }

    /** @return list<Command> */
    public static function builtinCommands(): array
    {
        return [
            new ListCommand(),
            new HelpCommand(),
            ...LegacyCommand::all(),
        ];
    }
}
