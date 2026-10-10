<?php
declare(strict_types=1);

namespace FloCMS\CLI\Discovery;

use FloCMS\CLI\Console\Command;
use ReflectionClass;
use Throwable;

/**
 * Turns class names and the application's commands/ folder into Command
 * instances. Problems are collected as warnings instead of stopping the CLI,
 * so one broken package command never takes down `php flo`.
 */
final class CommandLoader
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param list<string> $classes
     * @return list<Command>
     */
    public function fromClasses(array $classes, string $source): array
    {
        $commands = [];
        foreach ($classes as $class) {
            $command = $this->instantiate($class, $source);
            if ($command !== null) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /**
     * Commands in <root>/commands (namespace App\Commands, PSR-4). Files are
     * required directly when the class is not autoloadable, so older projects
     * without the "App\\Commands\\" autoload entry work too.
     *
     * @return list<Command>
     */
    public function fromDirectory(string $directory, string $namespace = 'App\\Commands\\'): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $commands = [];
        foreach ($files as $file) {
            $relative = substr($file, strlen(rtrim($directory, '/\\')) + 1, -4);
            $class = $namespace . str_replace(['/', '\\'], '\\', $relative);
            if (!class_exists($class)) {
                try {
                    require_once $file;
                } catch (Throwable $error) {
                    $this->warnings[] = 'Cannot load ' . $file . ': ' . $error->getMessage();
                    continue;
                }
            }
            if (!class_exists($class)) {
                $this->warnings[] = $file . ' does not define ' . $class . '.';
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Command::class)) {
                continue;
            }
            $command = $this->instantiate($class, 'commands/');
            if ($command !== null) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function instantiate(string $class, string $source): ?Command
    {
        try {
            if (!class_exists($class)) {
                $this->warnings[] = 'Command class ' . $class . ' (from ' . $source . ') not found.';

                return null;
            }
            $command = new $class();
            if (!$command instanceof Command) {
                $this->warnings[] = $class . ' (from ' . $source . ') does not extend ' . Command::class . '.';

                return null;
            }
            $command->getName();

            return $command;
        } catch (Throwable $error) {
            $this->warnings[] = 'Cannot create ' . $class . ' (from ' . $source . '): ' . $error->getMessage();

            return null;
        }
    }
}
