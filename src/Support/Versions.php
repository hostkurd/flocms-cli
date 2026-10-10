<?php
declare(strict_types=1);

namespace FloCMS\CLI\Support;

use Composer\InstalledVersions;
use FloCMS\CLI\Project;

/**
 * Installed versions, read from Composer (vendor/composer/installed.php)
 * instead of hard-coded strings.
 */
final class Versions
{
    public const CLI = 'hostkurd/flocms-cli';
    public const CORE = 'hostkurd/flocms-core';
    public const API = 'hostkurd/flocms-api';
    public const UPLOADER = 'hostkurd/flocms-uploader';
    public const SKELETON = 'hostkurd/flocms';

    /** The version of an installed package, or null when it is not installed. */
    public static function package(string $name): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }
        try {
            if (self::rootName() === $name) {
                return self::clean(InstalledVersions::getRootPackage()['pretty_version'] ?? null);
            }
            if (!InstalledVersions::isInstalled($name)) {
                return null;
            }

            return self::clean(InstalledVersions::getPrettyVersion($name));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function cli(): string
    {
        return self::package(self::CLI) ?? 'dev';
    }

    /**
     * The application's version: the skeleton's Composer version when the
     * project is hostkurd/flocms itself, otherwise APP_VERSION from .env.
     */
    public static function app(?Project $project): ?string
    {
        if (self::rootName() === self::SKELETON) {
            $version = self::package(self::SKELETON);
            if ($version !== null && !str_starts_with($version, 'dev-') && $version !== '1.0.0+no-version-set') {
                return $version;
            }
        }
        $version = $project?->env('APP_VERSION');

        return $version === null || $version === '' ? null : (string) $version;
    }

    /**
     * Framework package versions that are installed.
     *
     * @return array<string, string> label => version
     */
    public static function framework(?Project $project): array
    {
        $versions = [
            'cli' => self::cli(),
            'core' => self::package(self::CORE),
            'api' => self::package(self::API),
            'uploader' => self::package(self::UPLOADER),
            'app' => self::app($project),
        ];

        return array_filter($versions, static fn (?string $v): bool => $v !== null);
    }

    private static function rootName(): ?string
    {
        try {
            return InstalledVersions::getRootPackage()['name'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** "v2.0.0" -> "2.0.0" */
    public static function clean(?string $version): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }

        return preg_match('/^v\d/', $version) === 1 ? substr($version, 1) : $version;
    }
}
