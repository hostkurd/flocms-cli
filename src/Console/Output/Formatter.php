<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Output;

/**
 * Turns style tags into ANSI escape codes, or strips them when colors are off.
 *
 *     <info>green</info> <comment>yellow</comment> <error>white on red</error>
 *     <question>black on cyan</question> <warning>black on yellow</warning>
 *     <success>bold green</success> <muted>gray</muted> <b>bold</b>
 *     <fg=red;bg=white;options=bold,underscore>custom</>
 *
 * Write "\<" for a literal "<" that looks like a tag.
 */
final class Formatter
{
    private const FOREGROUND = [
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34,
        'magenta' => 35, 'cyan' => 36, 'white' => 37, 'gray' => 90, 'default' => 39,
    ];

    private const BACKGROUND = [
        'black' => 40, 'red' => 41, 'green' => 42, 'yellow' => 43, 'blue' => 44,
        'magenta' => 45, 'cyan' => 46, 'white' => 47, 'gray' => 100, 'default' => 49,
    ];

    private const OPTIONS = ['bold' => 1, 'dim' => 2, 'underscore' => 4, 'blink' => 5, 'reverse' => 7];

    /** @var array<string, string> named styles */
    private const STYLES = [
        'info' => 'fg=green',
        'comment' => 'fg=yellow',
        'error' => 'fg=white;bg=red',
        'question' => 'fg=black;bg=cyan',
        'warning' => 'fg=black;bg=yellow',
        'success' => 'fg=green;options=bold',
        'muted' => 'fg=gray',
        'b' => 'options=bold',
        'u' => 'options=underscore',
    ];

    private const TAG = '/(?<!\\\\)<(\/?)([a-z][a-z0-9_=;,-]*|)>/i';

    public function __construct(private bool $decorated = false)
    {
    }

    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    public function setDecorated(bool $decorated): void
    {
        $this->decorated = $decorated;
    }

    public function format(string $text): string
    {
        if (!str_contains($text, '<')) {
            return $text;
        }

        $stack = [];
        $result = (string) preg_replace_callback(self::TAG, function (array $match) use (&$stack): string {
            [$whole, $closing, $tag] = $match;
            if ($closing === '/') {
                if ($tag !== '' && ($stack === [] || end($stack)[0] !== strtolower($tag))) {
                    return $whole;
                }
                array_pop($stack);

                return $this->decorated ? "\033[0m" . implode('', array_column($stack, 1)) : '';
            }
            $codes = self::codes($tag);
            if ($codes === null) {
                return $whole;
            }
            $sequence = "\033[" . implode(';', $codes) . 'm';
            $stack[] = [strtolower($tag), $sequence];

            return $this->decorated ? $sequence : '';
        }, $text);

        if ($stack !== [] && $this->decorated) {
            $result .= "\033[0m";
        }

        return str_replace('\\<', '<', $result);
    }

    /** Remove tags and ANSI codes, e.g. to measure the visible width. */
    public static function strip(string $text): string
    {
        $text = (string) preg_replace_callback(self::TAG, static fn (array $m): string => self::codes($m[2]) !== null || $m[1] === '/' ? '' : $m[0], $text);
        $text = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);

        return str_replace('\\<', '<', $text);
    }

    /** Escape "<" so user data is never read as a tag. */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/(?<!\\\\)</', '\\<', $text);
    }

    /** Visible width of a (possibly tagged) string. */
    public static function width(string $text): int
    {
        $plain = self::strip($text);

        return function_exists('mb_strwidth') ? mb_strwidth($plain, 'UTF-8') : strlen($plain);
    }

    /** @return list<int>|null */
    private static function codes(string $tag): ?array
    {
        $tag = strtolower($tag);
        $definition = self::STYLES[$tag] ?? (str_contains($tag, '=') ? $tag : null);
        if ($definition === null) {
            return null;
        }
        $codes = [];
        foreach (explode(';', $definition) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            switch ($key) {
                case 'fg':
                    $codes[] = self::FOREGROUND[$value] ?? null;
                    break;
                case 'bg':
                    $codes[] = self::BACKGROUND[$value] ?? null;
                    break;
                case 'options':
                    foreach (explode(',', $value) as $option) {
                        $codes[] = self::OPTIONS[$option] ?? null;
                    }
                    break;
                default:
                    return null;
            }
        }
        if (in_array(null, $codes, true)) {
            return null;
        }

        return $codes;
    }
}
