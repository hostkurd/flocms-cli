<?php
namespace FloCMS\Controllers;

use FloCMS\Core\Controller;

class BlogPostController extends Controller
{
    protected array $actionPermissions = [
        'admin_delete' => 'content.delete',
    ];

    public function show(): void
    {
    }

    public function admin_delete(): void
    {
    }
}
