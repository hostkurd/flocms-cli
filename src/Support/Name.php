<?php
declare(strict_types=1);

namespace FloCMS\CLI\Support;

use FloCMS\CLI\Console\Input\InputException;

/**
 * Validates and converts the names given to generators. Only letters,
 * digits, "_" and "-" are accepted (starting with a letter), so a name can
 * never contain a path ("../../x", "C:\x", "a/b").
 */
final class Name
{
    public const PATTERN = '/^[A-Za-z][A-Za-z0-9_-]*$/D';

    /**
     * "blog-post", "blog_post" or "blogPost" -> "BlogPost". An optional
     * $suffix ("Controller") is removed first, so "BlogController" works too.
     */
    public static function studly(string $name, string $what = 'name', string $suffix = ''): string
    {
        $name = self::validate($name, $what);
        if ($suffix !== '' && strlen($name) > strlen($suffix) && strcasecmp(substr($name, -strlen($suffix)), $suffix) === 0) {
            $name = substr($name, 0, -strlen($suffix));
        }
        $studly = implode('', array_map(
            static fn (string $part): string => ucfirst($part),
            preg_split('/[-_]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));
        if ($studly === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $studly) !== 1) {
            throw new InputException('Invalid ' . $what . ' "' . $name . '".');
        }

        return $studly;
    }

    /** "BlogPost" / "blog-post" -> "blog_post" */
    public static function snake(string $name, string $what = 'name'): string
    {
        $name = self::validate($name, $what);
        $name = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name);

        return strtolower((string) preg_replace('/[-_]+/', '_', $name));
    }

    /** "BlogPost" / "blog_post" -> "blog-post" */
    public static function kebab(string $name, string $what = 'name'): string
    {
        return str_replace('_', '-', self::snake($name, $what));
    }

    /** "BlogPost" -> "blogpost": the URL / views folder form FloCMS uses for controllers. */
    public static function lower(string $name, string $what = 'name'): string
    {
        return strtolower(self::validate($name, $what));
    }

    /** A view or action name: letters, digits and "_" (e.g. "index", "admin_edit"). */
    public static function action(string $name, string $what = 'view name'): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InputException('Invalid ' . $what . ' "' . $name . '": use letters, digits and "_", starting with a letter.');
        }

        return $name;
    }

    public static function validate(string $name, string $what = 'name'): string
    {
        $name = trim($name, " \t");
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new InputException('Invalid ' . $what . ' "' . $name . '": use letters, digits, "_" and "-", starting with a letter.');
        }

        return $name;
    }
}
