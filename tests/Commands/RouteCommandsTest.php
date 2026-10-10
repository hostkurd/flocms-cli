<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Backlog B5. These commands boot the application (ROOT, config/config.php),
 * so they run in a separate process.
 */
final class RouteCommandsTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->skeleton();
        $this->project->fixture('site');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /** @return list<array<string, mixed>> */
    private function routes(array $options = []): array
    {
        $result = $this->project->flo(['route:list', '--format=json', ...$options]);
        self::assertSame(0, $result['code'], $result['stderr']);

        return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testLegacyRoutesFollowTheControllerConventions(): void
    {
        $routes = array_column($this->routes(), null, 'action');

        self::assertSame('/', $routes['PagesController@index']['uri']);
        self::assertSame('/pages/about', $routes['PagesController@about']['uri']);
        self::assertSame('/blog-post/show', $routes['BlogPostController@show']['uri']);
        self::assertSame('/admin/users/index', $routes['UsersController@admin_index']['uri']);
        self::assertSame('/login/users/reset', $routes['UsersController@login_reset']['uri']);
        self::assertSame('ANY', $routes['UsersController@verify']['method']);
        self::assertSame('web', $routes['UsersController@verify']['source']);

        // Permissions from $actionPermissions ('*' and explicit null)
        self::assertSame('users.manage', $routes['UsersController@admin_edit']['permission']);
        self::assertSame('users.manage', $routes['UsersController@verify']['permission']);
        self::assertNull($routes['UsersController@admin_login']['permission']);
        self::assertSame('content.delete', $routes['BlogPostController@admin_delete']['permission']);
        self::assertSame(['admin'], $routes['UsersController@admin_edit']['middleware']);

        // api_ needs LEGACY_API=true; protected methods are never routes
        self::assertArrayNotHasKey('PagesController@api_stats', $routes);
        self::assertArrayNotHasKey('PagesController@helper', $routes);
    }

    public function testLegacyApiRoutesWhenEnabled(): void
    {
        $this->project->write('.env', $this->project->read('.env') . "LEGACY_API=true\n");

        $routes = array_column($this->routes(), null, 'action');

        self::assertSame('/api/pages/stats', $routes['PagesController@api_stats']['uri']);
    }

    public function testFilters(): void
    {
        self::assertSame(['/admin/blog-post/delete', '/admin/users/index', '/admin/users/edit', '/admin/users/login', '/admin/users/profile'], array_column($this->routes(['--path=/admin']), 'uri'));
        self::assertSame(['BlogPostController@show'], array_column($this->routes(['--name=show']), 'action'));
        self::assertSame([], $this->routes(['--source=api']));
        self::assertCount(count($this->routes()), $this->routes(['--method=POST']));
    }

    public function testPackageRouteProviders(): void
    {
        $this->project->write('vendor/acme/api/src/Routes.php', '<?php
namespace Acme\Api;
final class Routes implements \FloCMS\CLI\Routing\RouteProvider {
    public function source(): string { return "api"; }
    public function routes(\FloCMS\CLI\Project $project): iterable {
        yield ["method" => "GET|HEAD", "uri" => "/api/v1/health", "name" => "health", "action" => "HealthController", "middleware" => ["throttle:public"]];
        yield ["method" => "POST", "uri" => "/api/v1/contact", "name" => "contact.store", "action" => "ContactController@store"];
    }
}');
        $this->project->installed([[
            'name' => 'acme/api',
            'install-path' => '../acme/api',
            'autoload' => ['psr-4' => ['Acme\\Api\\' => 'src/']],
            'extra' => ['flocms' => ['route-providers' => ['Acme\\Api\\Routes']]],
        ]]);
        $this->project->write('commands/LoadAcme.php', '<?php require_once dirname(__DIR__) . "/vendor/acme/api/src/Routes.php";');

        $api = $this->routes(['--source=api']);
        self::assertSame(['/api/v1/health', '/api/v1/contact'], array_column($api, 'uri'));
        self::assertSame(['/api/v1/contact'], array_column($this->routes(['--method=POST', '--source=api']), 'uri'));
        self::assertSame(['/api/v1/health'], array_column($this->routes(['--method=head', '--source=api']), 'uri'));

        $text = $this->project->flo(['route:list']);
        self::assertMatchesRegularExpression('/\| api +\| GET\|HEAD +\| \/api\/v1\/health +\| health +\| HealthController +\| throttle:public +\|/', $text['stdout']);
        self::assertStringContainsString('permission: users.manage, admin', $text['stdout']);
    }

    public function testPermissionList(): void
    {
        $result = $this->project->flo(['permission:list']);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertMatchesRegularExpression('/\| Editor \(1\) +\| content\.\* +\|/', $result['stdout']);
        self::assertMatchesRegularExpression('/\| BlogPostController +\| admin_delete +\| content\.delete +\| Editor \(1\), Admin \(2\), Super Admin \(3\) +\|/', $result['stdout']);
        self::assertMatchesRegularExpression('/\| UsersController +\| \* +\| users\.manage +\| Admin \(2\), Super Admin \(3\) +\|/', $result['stdout']);
        self::assertStringContainsString('(none: open to every admin)', $result['stdout']);

        $json = json_decode($this->project->flo(['permission:list', '--format=json'])['stdout'], true);
        self::assertSame(['*'], $json['roles'][3]);
    }
}
