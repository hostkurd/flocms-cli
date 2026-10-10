<?php
declare(strict_types=1);

namespace FloCMS\CLI\Scheduling;

use DateTimeImmutable;
use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Project;
use Throwable;

/**
 * Runs due events in this process (no proc_open needed on shared hosts),
 * with a lock per event so a slow run is not started twice, and records
 * the last run in storage/framework/schedule/state.json.
 */
final class Runner
{
    public function __construct(
        private readonly Project $project,
        private readonly Application $application
    ) {
    }

    /**
     * @return array{status: 'ok'|'failed'|'skipped', code: int, message: string, started: int, duration: float}
     */
    public function run(Event $event, Output $output): array
    {
        $directory = $this->project->path('storage/framework/schedule');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $lock = null;
        if ($event->preventsOverlapping()) {
            $lock = @fopen($directory . DIRECTORY_SEPARATOR . $event->id() . '.lock', 'c');
            if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);

                return $this->record($event, ['status' => 'skipped', 'code' => 0, 'message' => 'still running', 'started' => time(), 'duration' => 0.0]);
            }
        }

        $started = microtime(true);
        try {
            if ($event->command !== null) {
                $code = $this->application->runCommand($this->application->find($event->command), $event->arguments, $output);
                $message = $code === 0 ? '' : 'exit code ' . $code;
            } else {
                $result = ($event->callback)();
                $code = is_int($result) && $result < 0 ? 1 : 0;
                $message = is_int($result) && $result >= 0 ? (string) $result : '';
            }
        } catch (Throwable $error) {
            $code = 1;
            $message = get_class($error) . ': ' . $error->getMessage();
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $this->record($event, [
            'status' => $code === 0 ? 'ok' : 'failed',
            'code' => $code,
            'message' => $message,
            'started' => (int) $started,
            'duration' => round(microtime(true) - $started, 3),
        ]);
    }

    /** @return array<string, array{status: string, code: int, message: string, started: int, duration: float}> */
    public function state(): array
    {
        $file = $this->project->path('storage/framework/schedule/state.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array{status: string, code: int, message: string, started: int, duration: float} $result
     * @return array{status: string, code: int, message: string, started: int, duration: float}
     */
    private function record(Event $event, array $result): array
    {
        $state = $this->state();
        $state[$event->id()] = $result + ['name' => $event->name()];
        @file_put_contents($this->project->path('storage/framework/schedule/state.json'), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

        $line = '[' . date('Y-m-d H:i:s', $result['started']) . '] ' . $result['status'] . ': ' . $event->name()
            . ($result['message'] !== '' ? ' (' . $result['message'] . ')' : '') . ' ' . $result['duration'] . "s\n";
        $logs = $this->project->path('storage/logs');
        if (is_dir($logs)) {
            @file_put_contents($logs . DIRECTORY_SEPARATOR . 'schedule.log', $line, FILE_APPEND | LOCK_EX);
        }

        return $result;
    }

    public static function now(?string $at = null): DateTimeImmutable
    {
        return $at === null ? new DateTimeImmutable() : new DateTimeImmutable($at);
    }
}
