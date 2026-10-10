<?php
declare(strict_types=1);

namespace FloCMS\CLI\Support;

/**
 * Role/permission matching like flocms-core's Auth: "name", "prefix.*" and "*".
 */
final class Permissions
{
    /** Default role names of the FloCMS skeleton. */
    public const ROLES = [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin'];

    public static function matches(string $granted, string $permission): bool
    {
        if ($granted === '*' || $granted === $permission) {
            return true;
        }

        return str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1));
    }

    /**
     * Roles that have $permission.
     *
     * @param array<int|string, list<string>> $map role => granted permissions
     * @return list<int|string>
     */
    public static function rolesWith(array $map, string $permission): array
    {
        $roles = [];
        foreach ($map as $role => $granted) {
            foreach ((array) $granted as $entry) {
                if (self::matches((string) $entry, $permission)) {
                    $roles[] = $role;
                    break;
                }
            }
        }

        return $roles;
    }

    public static function roleName(int|string $role, array $names = self::ROLES): string
    {
        return isset($names[$role]) ? $names[$role] . ' (' . $role . ')' : (string) $role;
    }
}
