<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Output;

/**
 * Writes to STDOUT (results) and STDERR (errors, warnings, progress).
 */
class Output
{
    public const QUIET = 16;
    public const NORMAL = 32;
    public const VERBOSE = 64;
    public const VERY_VERBOSE = 128;
    public const DEBUG = 256;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private int $verbosity = self::NORMAL;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
        $this->stderr = $stderr ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
    }

    public function write(string $text, bool $newline = false, int $verbosity = self::NORMAL): void
    {
        if ($verbosity > $this->verbosity) {
            return;
        }
        fwrite($this->stdout, $text . ($newline ? "\n" : ''));
    }

    public function writeln(string $text = '', int $verbosity = self::NORMAL): void
    {
        $this->write($text, true, $verbosity);
    }

    /** Errors are written even with --quiet. */
    public function error(string $text, bool $newline = true): void
    {
        fwrite($this->stderr, $text . ($newline ? "\n" : ''));
    }

    public function verbosity(): int
    {
        return $this->verbosity;
    }

    public function setVerbosity(int $verbosity): void
    {
        $this->verbosity = $verbosity;
    }

    public function isQuiet(): bool
    {
        return $this->verbosity === self::QUIET;
    }

    public function isVerbose(): bool
    {
        return $this->verbosity >= self::VERBOSE;
    }

    public function isVeryVerbose(): bool
    {
        return $this->verbosity >= self::VERY_VERBOSE;
    }

    public function isDebug(): bool
    {
        return $this->verbosity >= self::DEBUG;
    }

    /** @return resource */
    public function stdout()
    {
        return $this->stdout;
    }

    /** @return resource */
    public function stderr()
    {
        return $this->stderr;
    }
}
