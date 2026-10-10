<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Output;

/**
 * A progress bar on STDERR. In a terminal it redraws one line; otherwise
 * (logs, CI) it prints a line at 25%, 50%, 75% and at the end.
 *
 *     $bar = $this->progress(count($files));
 *     foreach ($files as $file) { ...; $bar->advance(); }
 *     $bar->finish();
 */
final class ProgressBar
{
    private int $current = 0;

    private int $lastQuarter = 0;

    private string $message = '';

    public function __construct(private readonly Output $output, private int $max = 0, private readonly int $width = 28)
    {
    }

    public function start(?int $max = null, string $message = ''): self
    {
        $this->max = $max ?? $this->max;
        $this->current = 0;
        $this->lastQuarter = 0;
        $this->message = $message;
        $this->draw();

        return $this;
    }

    public function advance(int $step = 1, ?string $message = null): void
    {
        $this->setProgress($this->current + $step, $message);
    }

    public function setProgress(int $current, ?string $message = null): void
    {
        $this->current = $this->max > 0 ? min($current, $this->max) : $current;
        $this->message = $message ?? $this->message;
        $this->draw();
    }

    public function finish(?string $message = null): void
    {
        if ($this->max > 0) {
            $this->current = $this->max;
        }
        $this->message = $message ?? $this->message;
        if ($this->output->isErrorDecorated()) {
            $this->draw();
            $this->output->diagnostic('');
        } elseif ($this->lastQuarter < 4) {
            $this->lastQuarter = 4;
            $this->output->diagnostic($this->text());
        }
    }

    public function current(): int
    {
        return $this->current;
    }

    public function text(): string
    {
        if ($this->max <= 0) {
            return trim($this->current . ' ' . $this->message);
        }
        $percent = (int) floor($this->current / $this->max * 100);
        $done = (int) floor($this->width * $this->current / $this->max);
        $bar = str_repeat('=', $done) . ($done < $this->width ? '>' . str_repeat(' ', $this->width - $done - 1) : '');

        return trim(sprintf('%' . strlen((string) $this->max) . 'd/%d [%s] %3d%% %s', $this->current, $this->max, $bar, $percent, $this->message));
    }

    private function draw(): void
    {
        if ($this->output->isQuiet()) {
            return;
        }
        if ($this->output->isErrorDecorated()) {
            $this->output->diagnostic("\r\033[2K" . $this->text(), false);

            return;
        }
        if ($this->max > 0) {
            $quarter = (int) floor($this->current / $this->max * 4);
            if ($quarter > $this->lastQuarter && $quarter < 4) {
                $this->lastQuarter = $quarter;
                $this->output->diagnostic($this->text());
            }
        }
    }
}
