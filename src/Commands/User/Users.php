<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\User;

use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Support\Permissions;
use FloCMS\Core\Database;

/**
 * The FloCMS skeleton's users table (see models/UsersModel.php):
 * full_name, login, password (password_hash), email, role, status
 * (1 active, 2 pending, 3 suspended), is_verified, verify_type, token.
 */
final class Users
{
    public const ACTIVE = 1;
    public const PENDING = 2;
    public const SUSPENDED = 3;

    public const STATUSES = [self::ACTIVE => 'Active', self::PENDING => 'Pending', self::SUSPENDED => 'Suspended'];

    public const MIN_PASSWORD = 8;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $row = $this->db->table('users')->where('email', '=', trim($email))->first();

        return $row === null ? null : (array) $row;
    }

    /** @return array<string, mixed> */
    public function require(string $email): array
    {
        return $this->findByEmail($email) ?? throw new CommandFailedException('No user with the email "' . $email . '".');
    }

    public function loginExists(string $login): bool
    {
        return $this->db->table('users')->where('login', '=', $login)->first() !== null;
    }

    public function create(string $name, string $email, string $login, string $password, int $role): int
    {
        return $this->db->table('users')->insert([
            'full_name' => $name,
            'login' => $login,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email,
            'role' => $role,
            'status' => self::ACTIVE,
            'is_verified' => 1,
            'verify_type' => 2,
            'token' => '',
        ]);
    }

    /** @param array<string, mixed> $values */
    public function update(int $id, array $values): void
    {
        $this->db->table('users')->where('id', '=', $id)->update($values);
    }

    /**
     * @param array{role?: int|null, status?: int|null} $filters
     * @return list<array<string, mixed>>
     */
    public function all(array $filters = []): array
    {
        $query = $this->db->table('users');
        if (($filters['role'] ?? null) !== null) {
            $query->where('role', '=', $filters['role']);
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', '=', $filters['status']);
        }

        return array_map(static fn (object $row): array => (array) $row, $query->orderBy('id')->get());
    }

    /**
     * A role number from "3", "super-admin", "Super Admin", "admin", ...
     *
     * @param array<int, string> $names
     */
    public static function role(string $value, array $names = Permissions::ROLES): int
    {
        $value = trim($value);
        if (ctype_digit($value) && isset($names[(int) $value])) {
            return (int) $value;
        }
        $normalized = strtolower((string) preg_replace('/[\s_-]+/', '', $value));
        foreach ($names as $id => $name) {
            if (strtolower((string) preg_replace('/[\s_-]+/', '', $name)) === $normalized) {
                return (int) $id;
            }
        }

        throw new \FloCMS\CLI\Console\Input\InputException('Unknown role "' . $value . '". Use one of: ' . implode(', ', array_map(
            static fn (int $id, string $name): string => $id . ' (' . strtolower(str_replace(' ', '-', $name)) . ')',
            array_keys($names),
            $names
        )) . '.');
    }

    public static function validateEmail(string $email): ?string
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'Enter a valid email address.' : null;
    }

    public static function validatePassword(string $password): ?string
    {
        return strlen($password) < self::MIN_PASSWORD ? 'Use at least ' . self::MIN_PASSWORD . ' characters.' : null;
    }
}
