<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\User;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class UserPasswordCommand extends UserCommand
{
    protected string $name = 'user:password';

    protected string $description = 'Set a new password for a user';

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('email', Argument::REQUIRED, 'The user\'s email')
            ->option('password-stdin', null, Option::VALUE_NONE, 'Read the password from STDIN instead of asking');
    }

    protected function handle(): int
    {
        $users = $this->users();
        $user = $users->require((string) $this->argument('email'));
        $password = $this->newPassword((bool) $this->option('password-stdin'));
        $users->update((int) $user['id'], ['password' => password_hash($password, PASSWORD_DEFAULT)]);
        $this->success('Password changed for ' . $user['email'] . '.');

        return self::SUCCESS;
    }
}
