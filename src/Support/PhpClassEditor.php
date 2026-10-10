<?php
declare(strict_types=1);

namespace FloCMS\CLI\Support;

/**
 * Small, token-based edits of a PHP class file: find, add and remove
 * methods and use statements. Used by make:view (adds the controller
 * action) and the delete commands.
 */
final class PhpClassEditor
{
    public static function hasMethod(string $code, string $method): bool
    {
        return self::methodRange($code, $method) !== null;
    }

    /** Insert a method before the closing brace of the class. */
    public static function addMethod(string $code, string $methodCode): string
    {
        $end = self::classEnd($code);
        if ($end === null) {
            throw new \RuntimeException('No class body found.');
        }
        $before = rtrim(substr($code, 0, $end));
        $separator = str_ends_with($before, '{') ? "\n" : "\n\n";

        return $before . $separator . rtrim($methodCode) . "\n" . substr($code, $end);
    }

    /** Remove a method with its doc comment and attributes. */
    public static function removeMethod(string $code, string $method): string
    {
        $range = self::methodRange($code, $method);
        if ($range === null) {
            return $code;
        }
        [$start, $end] = $range;
        // Include the whole line and the blank line before the method
        $lineStart = strrpos(substr($code, 0, $start), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart;
        $before = rtrim(substr($code, 0, $lineStart), " \t");
        $after = ltrim(substr($code, $end), " \t");
        if (str_starts_with($after, "\n")) {
            $after = substr($after, 1);
        }
        $result = $before . "\n" . $after;

        return (string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace("/\{\n\n+/", "{\n", $result));
    }

    public static function addUse(string $code, string $class): string
    {
        $class = ltrim($class, '\\');
        if (preg_match('/^use\s+\\\\?' . preg_quote($class, '/') . '\s*;/m', $code) === 1) {
            return $code;
        }
        $line = 'use ' . $class . ';';
        if (preg_match_all('/^use\s+[^;(]+;[ \t]*$/m', $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($matches[0]);
            $position = $last[1] + strlen($last[0]);

            return substr($code, 0, $position) . "\n" . $line . substr($code, $position);
        }
        if (preg_match('/^namespace\s+[^;]+;[ \t]*$/m', $code, $match, PREG_OFFSET_CAPTURE) === 1) {
            $position = $match[0][1] + strlen($match[0][0]);

            return substr($code, 0, $position) . "\n\n" . $line . substr($code, $position);
        }

        return (string) preg_replace('/^<\?php\s*/', "<?php\n\n" . $line . "\n\n", $code, 1);
    }

    public static function removeUse(string $code, string $class): string
    {
        return (string) preg_replace('/^use\s+\\\\?' . preg_quote(ltrim($class, '\\'), '/') . '\s*;[ \t]*\R/m', '', $code);
    }

    /**
     * Byte offsets [start, end) of a method including its doc comment and
     * attributes, or null.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function methodRange(string $code, string $method): ?array
    {
        $tokens = token_get_all($code);
        $offset = 0;
        $positions = [];
        foreach ($tokens as $index => $token) {
            $positions[$index] = $offset;
            $offset += strlen(is_array($token) ? $token[1] : $token);
        }
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = self::next($tokens, $i);
            if ($j === null || !is_array($tokens[$j]) || strcasecmp($tokens[$j][1], $method) !== 0) {
                continue;
            }
            // Start: walk back over modifiers, attributes and the doc comment
            $start = $i;
            for ($k = $i - 1; $k >= 0; $k--) {
                $token = $tokens[$k];
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_DOC_COMMENT], true)) {
                    if ($token[0] !== T_WHITESPACE) {
                        $start = $k;
                    }
                    continue;
                }
                if ($token === ']' && self::attributeStart($tokens, $k) !== null) {
                    $k = $start = (int) self::attributeStart($tokens, $k);
                    continue;
                }
                break;
            }
            // End: the matching closing brace (or ";" for abstract methods)
            $depth = 0;
            for ($k = $j; $k < $count; $k++) {
                $token = $tokens[$k];
                $text = is_array($token) ? $token[1] : $token;
                if ($depth === 0 && $text === ';') {
                    return [$positions[$start], $positions[$k] + 1];
                }
                if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return [$positions[$start], $positions[$k] + 1];
                    }
                }
            }

            return null;
        }

        return null;
    }

    /** Offset of the class's closing brace. */
    private static function classEnd(string $code): ?int
    {
        $tokens = token_get_all($code);
        $offset = 0;
        $inClass = false;
        $depth = 0;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_CLASS, T_TRAIT], true)) {
                $inClass = true;
            }
            if ($inClass) {
                if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $offset;
                    }
                }
            }
            $offset += strlen($text);
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function next(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($tokens[$i] === '&') {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function attributeStart(array $tokens, int $closing): ?int
    {
        $depth = 0;
        for ($k = $closing; $k >= 0; $k--) {
            $token = $tokens[$k];
            if ($token === ']') {
                $depth++;
            } elseif ($token === '[') {
                $depth--;
            } elseif (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return null;
    }
}
