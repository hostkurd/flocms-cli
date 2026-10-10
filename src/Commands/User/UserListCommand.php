<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\User;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Permissions;

final class UserListCommand extends UserCommand
{
    protected string $name = 'user:list';

    protected string $description = 'List users';

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('role', 'r', Option::VALUE_REQUIRED, 'Only this role')
            ->option('status', 's', Option::VALUE_REQUIRED, 'Only this status: active, pending or suspended')
            ->option('format', null, Option::VALUE_REQUIRED, 'txt or json', 'txt');
    }

    protected function handle(): int
    {
        $names = $this->roleNames();
        $status = $this->option('status');
        if ($status !== null) {
            $status = array_search(ucfirst(strtolower((string) $status)), Users::STATUSES, true);
            if ($status === false) {
                $this->invalid('--status must be active, pending or suspended.');
            }
        }
        $rows = $this->users()->all([
            'role' => $this->option('role') !== null ? Users::role((string) $this->option('role'), $names) : null,
            'status' => $status,
        ]);
        $rows = array_map(static fn (array $u): array => [
            'id' => (int) $u['id'],
            'name' => (string) ($u['full_name'] ?? ''),
            'email' => (string) $u['email'],
            'login' => (string) ($u['login'] ?? ''),
            'role' => (int) $u['role'],
            'status' => (int) $u['status'],
            'verified' => (bool) ($u['is_verified'] ?? false),
        ], $rows);

        if ($this->option('format') === 'json') {
            $this->output->write(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

            return self::SUCCESS;
        }
        if ($rows === []) {
            $this->line('No users.');

            return self::SUCCESS;
        }
        $this->table(['ID', 'Name', 'Email', 'Role', 'Status', 'Verified'], array_map(static fn (array $u): array => [
            $u['id'],
            $u['name'],
            $u['email'],
            Permissions::roleName($u['role'], $names),
            Users::STATUSES[$u['status']] ?? (string) $u['status'],
            $u['verified'],
        ], $rows));

        return self::SUCCESS;
    }
}
