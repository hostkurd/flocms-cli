<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Output;

/**
 * Writes to STDOUT (results) and STDERR (errors, warnings, progress), with
 * style tags (see Formatter) rendered as colors when the stream supports it.
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

    private Formatter $formatter;

    private Formatter $errorFormatter;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param bool|null $decorated null = detect (TTY, NO_COLOR, Windows VT100)
     */
    public function __construct($stdout = null, $stderr = null, ?bool $decorated = null)
    {
        $this->stdout = $stdout ?? (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
        $this->stderr = $stderr ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
        $this->formatter = new Formatter($decorated ?? self::supportsColor($this->stdout));
        $this->errorFormatter = new Formatter($decorated ?? self::supportsColor($this->stderr));
    }

    /**
     * Colors when the stream is a terminal, NO_COLOR is not set and (on
     * Windows) the console supports VT100 sequences.
     *
     * @param resource $stream
     */
    public static function supportsColor($stream): bool
    {
        if (isset($_SERVER['NO_COLOR']) || getenv('NO_COLOR') !== false) {
            return false;
        }
        if (getenv('TERM') === 'dumb') {
            return false;
        }
        if (!self::isTerminal($stream)) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support($stream, true))
                || getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || getenv('TERM_PROGRAM') === 'vscode'
                || getenv('WT_SESSION') !== false;
        }

        return true;
    }

    /** @param resource $stream */
    public static function isTerminal($stream): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty($stream);
        }

        return false;
    }

    public function write(string $text, bool $newline = false, int $verbosity = self::NORMAL): void
    {
        if ($verbosity > $this->verbosity) {
            return;
        }
        fwrite($this->stdout, $this->formatter->format($text) . ($newline ? "\n" : ''));
        fflush($this->stdout);
    }

    public function writeln(string $text = '', int $verbosity = self::NORMAL): void
    {
        $this->write($text, true, $verbosity);
    }

    /** Errors are written even with --quiet. */
    public function error(string $text, bool $newline = true): void
    {
        fwrite($this->stderr, $this->errorFormatter->format($text) . ($newline ? "\n" : ''));
        fflush($this->stderr);
    }

    /** Diagnostics (progress, warnings) on STDERR, respecting --quiet. */
    public function diagnostic(string $text, bool $newline = true, int $verbosity = self::NORMAL): void
    {
        if ($verbosity > $this->verbosity) {
            return;
        }
        $this->error($text, $newline);
    }

    public function isDecorated(): bool
    {
        return $this->formatter->isDecorated();
    }

    public function isErrorDecorated(): bool
    {
        return $this->errorFormatter->isDecorated();
    }

    public function setDecorated(bool $decorated): void
    {
        $this->formatter->setDecorated($decorated);
        $this->errorFormatter->setDecorated($decorated);
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

    /** Terminal width, 80 when unknown. */
    public function width(): int
    {
        $columns = getenv('COLUMNS');
        if (is_string($columns) && ctype_digit($columns) && (int) $columns > 20) {
            return (int) $columns;
        }

        return 80;
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
