<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Output;

/**
 * A spinner for work of unknown length. Call tick() while working; in a
 * terminal it redraws, otherwise it prints the message once.
 *
 *     $result = $this->spin('Connecting to the database', fn () => $db->check());
 */
final class Spinner
{
    private const FRAMES = ['|', '/', '-', '\\'];

    private int $frame = 0;

    private bool $started = false;

    public function __construct(private readonly Output $output, private string $message)
    {
    }

    public function tick(?string $message = null): void
    {
        $this->message = $message ?? $this->message;
        if ($this->output->isQuiet()) {
            return;
        }
        if ($this->output->isErrorDecorated()) {
            $this->output->diagnostic("\r\033[2K" . self::FRAMES[$this->frame++ % 4] . ' ' . $this->message, false);
        } elseif (!$this->started) {
            $this->output->diagnostic($this->message . '...');
        }
        $this->started = true;
    }

    public function finish(string $result = 'done'): void
    {
        if ($this->output->isQuiet()) {
            return;
        }
        if ($this->output->isErrorDecorated()) {
            $this->output->diagnostic("\r\033[2K" . $this->message . ': ' . $result);
        }
    }

    /**
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        $this->tick();
        try {
            $result = $work($this);
            $this->finish('<info>done</info>');

            return $result;
        } catch (\Throwable $error) {
            $this->finish('<error>failed</error>');
            throw $error;
        }
    }
}
