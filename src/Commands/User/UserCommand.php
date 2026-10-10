<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\User;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Database\Migrator;
use FloCMS\CLI\Support\Permissions;
use FloCMS\Core\Config;

/**
 * Shared helpers for the user:* commands.
 */
abstract class UserCommand extends Command
{
    protected function users(): Users
    {
        $project = $this->project();
        $db = $project->database();
        if (!(new Migrator($project, $db))->tableExists('users')) {
            $this->fail('The users table does not exist yet. Run "php flo migrate" (FloCMS 1.7+ ships the migration) or import your database.');
        }

        return new Users($db);
    }

    /**
     * Role names from config 'roles' when the project defines them, else the
     * skeleton's defaults (0 User, 1 Editor, 2 Admin, 3 Super Admin).
     *
     * @return array<int, string>
     */
    protected function roleNames(): array
    {
        $names = class_exists(Config::class) ? Config::get('roles') : null;

        return is_array($names) && $names !== [] ? $names : Permissions::ROLES;
    }

    /**
     * A password from --password-stdin (scripts), or a hidden prompt asked
     * twice.
     */
    protected function newPassword(bool $fromStdin): string
    {
        if ($fromStdin) {
            $line = fgets($this->input->stream());
            $password = $line === false ? '' : rtrim($line, "\r\n");
            $error = Users::validatePassword($password);
            if ($error !== null) {
                $this->fail('Password from STDIN rejected: ' . $error);
            }

            return $password;
        }
        if (!$this->input->isInteractive()) {
            $this->fail('A password is needed: run interactively, or pipe it in with --password-stdin.');
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $password = $this->secret('Password (at least ' . Users::MIN_PASSWORD . ' characters)', [Users::class, 'validatePassword']);
            if ($this->secret('Repeat the password') === $password) {
                return $password;
            }
            $this->output->error('<comment>The passwords do not match.</comment>');
        }
        $this->fail('The passwords did not match.');
    }
}
