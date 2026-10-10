<?php
declare(strict_types=1);

namespace FloCMS\CLI;

use FloCMS\CLI\Commands\App;
use FloCMS\CLI\Commands\App\KeyGenerateCommand;
use FloCMS\CLI\Commands\Cache;
use FloCMS\CLI\Commands\Db;
use FloCMS\CLI\Commands\HelpCommand;
use FloCMS\CLI\Commands\ListCommand;
use FloCMS\CLI\Commands\Make;
use FloCMS\CLI\Commands\Route;
use FloCMS\CLI\Commands\StubPublishCommand;
use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Discovery\CommandLoader;
use FloCMS\CLI\Discovery\PackageManifest;
use FloCMS\CLI\Support\Versions;

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
        $application = new Application('FloCMS CLI', Versions::cli(), $project, $output, $stdin);
        $application->setHeaderProvider(static fn (): array => self::header($project));
        $application->setVersionProvider(static fn (): string => self::longVersion($project));

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

    /**
     * The top of `php flo list`.
     *
     * @return list<string>
     */
    public static function header(Project $project): array
    {
        $versions = Versions::framework($project);
        $parts = [];
        foreach (['core' => 'flocms-core', 'api' => 'flocms-api', 'uploader' => 'flocms-uploader', 'app' => 'app'] as $key => $label) {
            if (isset($versions[$key])) {
                $parts[] = $label . ' <comment>' . $versions[$key] . '</comment>';
            }
        }
        $debug = $project->env('APP_DEBUG') === true ? ', <comment>debug on</comment>' : '';

        return array_values(array_filter([
            '<info>FloCMS CLI</info> <comment>' . Versions::cli() . '</comment>',
            $parts === [] ? null : 'Framework: ' . implode(', ', $parts),
            'Environment: <comment>' . ($project->hasEnvFile() || $project->env('APP_ENV') !== null ? $project->environment() : 'no .env file') . '</comment>' . $debug,
        ]));
    }

    /** -V / --version */
    public static function longVersion(Project $project): string
    {
        $versions = Versions::framework($project);
        unset($versions['cli']);
        $parts = [];
        foreach ($versions as $label => $version) {
            $parts[] = $label . ' ' . $version;
        }

        return 'FloCMS CLI ' . Versions::cli() . ($parts === [] ? '' : ' (' . implode(', ', $parts) . ')');
    }

    /** @return list<Command> */
    public static function builtinCommands(): array
    {
        return [
            new ListCommand(),
            new HelpCommand(),
            new KeyGenerateCommand(),
            new App\AboutCommand(),
            new App\DoctorCommand(),
            new App\ServeCommand(),
            new App\DownCommand(),
            new App\UpCommand(),
            new App\EnvCheckCommand(),
            new Cache\CacheClearCommand(),
            new Cache\ViewClearCommand(),
            new Cache\OptimizeCommand(),
            new Cache\OptimizeClearCommand(),
            new Route\RouteListCommand(),
            new Route\PermissionListCommand(),
            new Make\MakeControllerCommand(),
            new Make\MakeModelCommand(),
            new Make\MakeViewCommand(),
            new Make\MakeRouteCommand(),
            new Make\MakeApiControllerCommand(),
            new Make\MakeResourceCommand(),
            new Make\MakeMiddlewareCommand(),
            new Make\MakeRequestCommand(),
            new Make\MakeMigrationCommand(),
            new Make\MakeSeederCommand(),
            new Make\MakeCommandCommand(),
            new Make\MakeTestCommand(),
            new Make\MakeModuleCommand(),
            ...Make\DeleteCommand::all(),
            new StubPublishCommand(),
            new Db\MigrateCommand(),
            new Db\MigrateStatusCommand(),
            new Db\MigrateRollbackCommand(),
            new Db\MigrateFreshCommand(),
            new Db\DbSeedCommand(),
            new Db\DbShowCommand(),
            new Db\DbCheckCommand(),
        ];
    }
}
