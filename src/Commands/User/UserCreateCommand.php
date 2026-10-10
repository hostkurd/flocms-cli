<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\User;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Permissions;

final class UserCreateCommand extends UserCommand
{
    protected string $name = 'user:create';

    protected string $description = 'Create a user (the safe way to create the first admin)';

    protected string $help = <<<'TXT'
Asks for the name, email and password (hidden). The user is active and
verified. For scripts, pass --name and --email with --no-interaction and
pipe the password in: echo "$PASSWORD" | php flo user:create -n --password-stdin ...
TXT;

    protected array $examples = [
        'php flo user:create',
        'php flo user:create --role=super-admin',
        'php flo user:create -n --name="Site Admin" --email=admin@example.com --role=admin --password-stdin < password.txt',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('name', null, Option::VALUE_REQUIRED, 'Full name')
            ->option('email', null, Option::VALUE_REQUIRED, 'Email address')
            ->option('login', null, Option::VALUE_REQUIRED, 'Login name (default: the email)')
            ->option('role', 'r', Option::VALUE_REQUIRED, 'Role: user, editor, admin, super-admin, or its number')
            ->option('password-stdin', null, Option::VALUE_NONE, 'Read the password from STDIN instead of asking');
    }

    protected function handle(): int
    {
        $users = $this->users();
        $names = $this->roleNames();

        $name = $this->option('name') ?? $this->ask('Full name', null, static fn (string $v): ?string => trim($v) === '' ? 'Enter a name.' : null);
        $email = $this->option('email') ?? $this->ask('Email', null, [Users::class, 'validateEmail']);
        $email = trim((string) $email);
        if (($error = Users::validateEmail($email)) !== null) {
            $this->invalid($error);
        }
        if ($users->findByEmail($email) !== null) {
            $this->fail('A user with the email "' . $email . '" already exists. Use user:password or user:role to change it.');
        }
        $login = trim((string) ($this->option('login') ?? $email));
        if ($users->loginExists($login)) {
            $this->fail('The login "' . $login . '" is already taken.');
        }

        $roleOption = $this->option('role');
        $role = $roleOption !== null
            ? Users::role((string) $roleOption, $names)
            : (int) $this->choice('Role', array_map('strval', $names), (int) array_key_last($names));

        $password = $this->newPassword((bool) $this->option('password-stdin'));
        $id = $users->create(trim((string) $name), $email, $login, $password, $role);

        $this->success('User #' . $id . ' created: ' . $email . ' (' . Permissions::roleName($role, $names) . ', active).');

        return self::SUCCESS;
    }
}
