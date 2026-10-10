<?php
namespace FloCMS\Controllers;

use FloCMS\Core\Controller;

class UsersController extends Controller
{
    protected array $actionPermissions = [
        '*' => 'users.manage',
        'admin_login' => null,
        'admin_profile' => null,
    ];

    public function verify(): void
    {
    }

    public function admin_index(): void
    {
    }

    public function admin_edit(): void
    {
    }

    public function admin_login(): void
    {
    }

    public function admin_profile(): void
    {
    }

    public function login_reset(): void
    {
    }
}
