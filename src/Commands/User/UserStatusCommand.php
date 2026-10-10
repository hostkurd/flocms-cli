<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\User;

use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;

/**
 * user:suspend and user:unsuspend.
 */
final class UserStatusCommand extends UserCommand
{
    private function __construct(string $name, string $description, private readonly int $status)
    {
        $this->name = $name;
        $this->description = $description;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self('user:suspend', 'Suspend a user (they can no longer sign in)', Users::SUSPENDED),
            new self('user:unsuspend', 'Reactivate a suspended user', Users::ACTIVE),
        ];
    }

    protected function configure(Definition $definition): void
    {
        $definition->argument('email', Argument::REQUIRED, 'The user\'s email');
    }

    protected function handle(): int
    {
        $users = $this->users();
        $user = $users->require((string) $this->argument('email'));
        if ((int) $user['status'] === $this->status) {
            $this->info($user['email'] . ' is already ' . strtolower(Users::STATUSES[$this->status]) . '.');

            return self::SUCCESS;
        }
        $users->update((int) $user['id'], ['status' => $this->status]);
        $this->success($user['email'] . ' is now ' . strtolower(Users::STATUSES[$this->status]) . '.');
        if ($this->status === Users::SUSPENDED) {
            $this->note('With flocms-core 2.2 (auth.user_loader) open admin sessions lose access on their next request. Revoke API tokens with: php flo api:token:revoke --user=' . $user['id']);
        }

        return self::SUCCESS;
    }
}
