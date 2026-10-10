<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\User;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Support\Permissions;

final class UserRoleCommand extends UserCommand
{
    protected string $name = 'user:role';

    protected string $description = 'Change a user\'s role';

    protected array $examples = ['php flo user:role editor@example.com editor', 'php flo user:role admin@example.com 3'];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('email', Argument::REQUIRED, 'The user\'s email')
            ->argument('role', Argument::REQUIRED, 'user, editor, admin, super-admin, or the role number');
    }

    protected function handle(): int
    {
        $names = $this->roleNames();
        $role = Users::role((string) $this->argument('role'), $names);
        $users = $this->users();
        $user = $users->require((string) $this->argument('email'));
        $users->update((int) $user['id'], ['role' => $role]);
        $this->success($user['email'] . ' is now ' . Permissions::roleName($role, $names) . ' (was ' . Permissions::roleName((int) $user['role'], $names) . ').');

        return self::SUCCESS;
    }
}
