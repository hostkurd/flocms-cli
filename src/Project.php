<?php
declare(strict_types=1);

namespace FloCMS\CLI;

/**
 * The FloCMS application the CLI works on.
 */
class Project
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Absolute path of a project-relative path. */
    public function path(string $relative = ''): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        return $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function vendorDir(): string
    {
        return $this->path('vendor');
    }
}
