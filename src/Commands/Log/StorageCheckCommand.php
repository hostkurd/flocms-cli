<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\Log;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class StorageCheckCommand extends Command
{
    /** Directories FloCMS writes to. */
    public const DIRECTORIES = ['storage', 'storage/logs', 'storage/cache', 'storage/framework', 'views/cache', 'public/uploads'];

    protected string $name = 'storage:check';

    protected string $description = 'Check that the directories FloCMS writes to exist and are writable';

    protected string $help = 'With --fix, missing directories are created (0775). Exit code 1 when one is missing or not writable.';

    protected function configure(Definition $definition): void
    {
        $definition->option('fix', null, Option::VALUE_NONE, 'Create missing directories');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $rows = [];
        $problems = 0;
        foreach (self::DIRECTORIES as $directory) {
            $path = $project->path($directory);
            if (!is_dir($path) && $this->option('fix')) {
                @mkdir($path, 0775, true);
            }
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $problems += $writable ? 0 : 1;
            $rows[] = [
                $directory . '/',
                $exists ? self::permissions($path) : '-',
                $exists ? self::owner($path) : '-',
                new \FloCMS\CLI\Console\Output\Styled(match (true) {
                    !$exists => '<error>missing</error>',
                    !$writable => '<error>not writable</error>',
                    default => '<info>writable</info>',
                }),
            ];
        }
        $this->table(['Directory', 'Mode', 'Owner', 'Status'], $rows);
        if ($problems > 0) {
            $this->error($problems . ' director' . ($problems === 1 ? 'y needs' : 'ies need') . ' attention. Create them (--fix) and make them writable by the web server user, e.g. chmod -R 775 storage views/cache.');

            return self::FAILURE;
        }
        $this->success('All storage directories are writable.');

        return self::SUCCESS;
    }

    private static function permissions(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? (is_writable($path) ? 'rw' : 'r') : substr(sprintf('%o', fileperms($path)), -4);
    }

    private static function owner(string $path): string
    {
        $uid = @fileowner($path);
        if ($uid !== false && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);
            if (is_array($info)) {
                return (string) $info['name'];
            }
        }

        return $uid === false ? '-' : (string) $uid;
    }
}
