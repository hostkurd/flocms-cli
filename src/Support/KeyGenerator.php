<?php
declare(strict_types=1);

namespace FloCMS\CLI\Support;

use RuntimeException;

/**
 * Generates a per-site APP_KEY and writes it to .env.
 * (Moved from the FloCMS skeleton's FloCMS\Support\KeyGenerator.)
 */
final class KeyGenerator
{
    public static function generate(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** Whether $envFile has a non-empty APP_KEY. */
    public static function hasKey(string $envFile): bool
    {
        $contents = is_file($envFile) ? (string) file_get_contents($envFile) : '';

        return preg_match('/^APP_KEY=(.*)$/m', $contents, $m) === 1 && trim($m[1], " \t\r\"'") !== '';
    }

    /**
     * Set APP_KEY in $envFile when it is empty (or always with $force).
     *
     * @return string|null the new key, or null when a key was already set
     */
    public static function writeToEnv(string $envFile, bool $force = false): ?string
    {
        if (!is_file($envFile)) {
            throw new RuntimeException(".env file not found: {$envFile}");
        }

        $contents = (string) file_get_contents($envFile);

        if (preg_match('/^APP_KEY=(.*)$/m', $contents, $m)) {
            if (!$force && trim($m[1], " \t\r\"'") !== '') {
                return null;
            }

            $key = self::generate();
            $contents = (string) preg_replace_callback('/^APP_KEY=.*$/m', static fn (): string => 'APP_KEY=' . $key, $contents, 1);
        } else {
            $key = self::generate();
            $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
            $contents = rtrim($contents, "\r\n") . $newline . 'APP_KEY=' . $key . $newline;
        }

        if (file_put_contents($envFile, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write {$envFile}");
        }

        return $key;
    }
}
