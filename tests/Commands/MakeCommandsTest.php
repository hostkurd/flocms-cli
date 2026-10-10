<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Support\Stubs;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MakeCommandsTest extends TestCase
{
    private TempProject $project;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->write('composer.json', json_encode([
            'name' => 'test/site',
            'require' => ['hostkurd/flocms-core' => '2.2.0'],
            'autoload-dev' => ['psr-4' => ['HostKurd\\Flocms\\Tests\\' => 'tests/']],
        ]));
        $this->tester = ApplicationTester::forProject($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /** @param list<string> $args */
    private function flo(array $args, array $inputs = []): int
    {
        return $this->tester->run($args, $inputs);
    }

    private function assertValidPhp(string $relative): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->project->path($relative)) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, $relative . ":\n" . implode("\n", $output));
    }

    public function testMakeController(): void
    {
        self::assertSame(0, $this->flo(['make:controller', 'blog-post']));
        self::assertStringContainsString('Created controllers/BlogPostController.php', $this->tester->getDisplay());

        $code = $this->project->read('controllers/BlogPostController.php');
        self::assertStringContainsString('class BlogPostController extends Controller', $code);
        self::assertStringContainsString('public function index(): void', $code);
        self::assertStringContainsString('GET /blog-post', $code);
        self::assertStringNotContainsString('Index()', $code);
        self::assertStringNotContainsString("\t", $code);
        self::assertStringNotContainsString('Model', $code);
        $this->assertValidPhp('controllers/BlogPostController.php');
    }

    public function testExistingFilesNeedForce(): void
    {
        $this->flo(['make:controller', 'Blog']);
        $this->project->write('controllers/BlogController.php', '<?php // mine');

        self::assertSame(1, $this->flo(['make:controller', 'Blog']));
        self::assertStringContainsString('controllers/BlogController.php already exists (use --force to overwrite).', $this->tester->getErrorOutput());
        self::assertSame('<?php // mine', $this->project->read('controllers/BlogController.php'));

        self::assertSame(0, $this->flo(['make:controller', 'Blog', '--force']));
        self::assertStringContainsString('Overwrote controllers/BlogController.php', $this->tester->getDisplay());
        self::assertStringContainsString('class BlogController', $this->project->read('controllers/BlogController.php'));
    }

    public function testDryRunWritesNothing(): void
    {
        self::assertSame(0, $this->flo(['make:route', 'Blog', '--dry-run', '-v']));

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Would create models/BlogModel.php', $display);
        self::assertStringContainsString('Would create controllers/BlogController.php', $display);
        self::assertStringContainsString('Would create views/blog/index.html', $display);
        self::assertStringContainsString('class BlogController extends Controller', $display);
        self::assertDirectoryDoesNotExist($this->project->path('controllers'));
        self::assertDirectoryDoesNotExist($this->project->path('models'));
    }

    public function testControllerWithModelAdminAndResource(): void
    {
        self::assertSame(0, $this->flo(['make:controller', 'Post', '--resource', '--model']));
        $code = $this->project->read('controllers/PostController.php');
        self::assertStringContainsString('use FloCMS\\Models\\PostModel;', $code);
        self::assertStringContainsString('return $this->model ??= new PostModel();', $code);
        foreach (['index', 'view', 'admin_index', 'admin_add', 'admin_edit', 'admin_delete'] as $action) {
            self::assertStringContainsString('public function ' . $action . '(): void', $code);
        }
        self::assertStringContainsString("'admin_edit' => 'post.update'", $code);
        self::assertFileExists($this->project->path('models/PostModel.php'));
        $this->assertValidPhp('controllers/PostController.php');

        self::assertSame(0, $this->flo(['make:controller', 'Reports', '--admin']));
        $admin = $this->project->read('controllers/ReportsController.php');
        self::assertStringContainsString("'*' => 'reports.manage'", $admin);
        self::assertStringContainsString('public function admin_index(): void', $admin);
        $this->assertValidPhp('controllers/ReportsController.php');
    }

    public function testMakeModelWiresAnExistingController(): void
    {
        $this->flo(['make:controller', 'Category']);

        self::assertSame(0, $this->flo(['make:model', 'Category']));
        $model = $this->project->read('models/CategoryModel.php');
        self::assertStringContainsString("protected string \$table = 'categories';", $model);
        $this->assertValidPhp('models/CategoryModel.php');

        $controller = $this->project->read('controllers/CategoryController.php');
        self::assertStringContainsString('use FloCMS\\Models\\CategoryModel;', $controller);
        self::assertStringContainsString('protected function model(): CategoryModel', $controller);
        $this->assertValidPhp('controllers/CategoryController.php');

        $this->flo(['make:model', 'Box', '--table=crates', '--migration']);
        self::assertStringContainsString("'crates'", $this->project->read('models/BoxModel.php'));
        $migrations = glob($this->project->path('database/migrations') . '/*_create_crates_table.php') ?: [];
        self::assertCount(1, $migrations);
    }

    public function testMakeViewAddsTheAction(): void
    {
        $this->flo(['make:controller', 'Blog']);

        self::assertSame(0, $this->flo(['make:view', 'Blog', 'show']));
        self::assertFileExists($this->project->path('views/blog/show.html'));
        self::assertStringContainsString('Updated controllers/BlogController.php', $this->tester->getDisplay());
        self::assertSame(0, $this->flo(['make:view', 'blog', 'edit', '--admin']));
        self::assertFileExists($this->project->path('views/blog/admin_edit.html'));

        $code = $this->project->read('controllers/BlogController.php');
        self::assertStringContainsString("    public function show(): void\n", $code);
        self::assertStringContainsString('GET /admin/blog/edit', $code);
        self::assertStringContainsString('public function admin_edit(): void', $code);
        $this->assertValidPhp('controllers/BlogController.php');

        // A view without a controller is fine; the same view again needs --force
        self::assertSame(0, $this->flo(['make:view', 'Orphan', 'index']));
        self::assertSame(1, $this->flo(['make:view', 'Blog', 'show']));
        self::assertSame(1, substr_count($this->project->read('controllers/BlogController.php'), 'function show('));
    }

    public function testMakeRoute(): void
    {
        self::assertSame(0, $this->flo(['create:route', 'News', 'latest']));

        self::assertFileExists($this->project->path('models/NewsModel.php'));
        self::assertFileExists($this->project->path('views/news/latest.html'));
        $code = $this->project->read('controllers/NewsController.php');
        self::assertStringContainsString('new NewsModel()', $code);
        self::assertStringContainsString('public function latest(): void', $code);
        $this->assertValidPhp('controllers/NewsController.php');
        self::assertStringContainsString("'news'", $this->project->read('models/NewsModel.php'));
    }

    /** @return array<string, array{0: list<string>, 1: string, 2: list<string>}> */
    public static function classGenerators(): array
    {
        return [
            'api controller' => [['make:api-controller', 'Posts'], 'api/Controllers/PostsController.php', ['namespace App\\Api\\Controllers;', 'final class PostsController extends Controller', "#[Tag('Posts')]"]],
            'api resource controller' => [['make:controller', 'Posts', '--api', '--resource'], 'api/Controllers/PostsController.php', ['public function destroy(Request $request): Response', 'use ValidatesRequests;']],
            'resource' => [['make:resource', 'Post'], 'api/Resources/PostResource.php', ['final class PostResource extends JsonResource']],
            'middleware' => [['make:middleware', 'RequireTenant'], 'api/Middleware/RequireTenant.php', ['final class RequireTenant implements MiddlewareInterface']],
            'request' => [['make:request', 'StorePost'], 'api/Requests/StorePostRequest.php', ['final class StorePostRequest', 'public static function rules(): array']],
            'seeder' => [['make:seeder', 'Posts'], 'database/seeders/PostsSeeder.php', ['final class PostsSeeder extends Seeder', 'php flo db:seed --class=PostsSeeder']],
            'command' => [['make:command', 'SendReports'], 'commands/SendReportsCommand.php', ["protected string \$name = 'app:send-reports';", 'namespace App\\Commands;']],
            'named command' => [['make:command', 'Sync', '--command=shop:sync'], 'commands/SyncCommand.php', ["'shop:sync'"]],
            'unit test' => [['make:test', 'Slug'], 'tests/Unit/SlugTest.php', ['namespace HostKurd\\Flocms\\Tests\\Unit;', 'final class SlugTest extends TestCase']],
            'feature test' => [['make:test', 'Checkout', '--feature'], 'tests/Feature/CheckoutTest.php', ['namespace HostKurd\\Flocms\\Tests\\Feature;', 'ApplicationTester::forProject']],
            'module' => [['make:module', 'shop-orders'], 'modules/shop-orders/module.php', ["'id' => 'shop-orders'", "'App\\\\Modules\\\\ShopOrders\\\\' => 'src/'", 'App\\Modules\\ShopOrders\\ShopOrdersServiceProvider::class']],
        ];
    }

    /**
     * @param list<string> $args
     * @param list<string> $contains
     */
    #[DataProvider('classGenerators')]
    public function testClassGenerators(array $args, string $file, array $contains): void
    {
        self::assertSame(0, $this->flo($args), $this->tester->getErrorOutput());
        self::assertFileExists($this->project->path($file));
        $code = $this->project->read($file);
        foreach ($contains as $text) {
            self::assertStringContainsString($text, $code);
        }
        $this->assertValidPhp($file);
    }

    public function testModuleProviderIsValid(): void
    {
        $this->flo(['make:module', 'news']);

        $this->assertValidPhp('modules/news/src/NewsServiceProvider.php');
        $manifest = require $this->project->path('modules/news/module.php');
        self::assertSame('news', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
    }

    public function testMakeMigration(): void
    {
        self::assertSame(0, $this->flo(['make:migration', 'create_posts_table']));
        $files = glob($this->project->path('database/migrations') . '/*.php') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_posts_table\.php$/', basename($files[0]));
        $code = (string) file_get_contents($files[0]);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `posts`', $code);
        self::assertStringContainsString('DROP TABLE IF EXISTS `posts`', $code);

        self::assertSame(1, $this->flo(['make:migration', 'create_posts_table']));
        self::assertStringContainsString('already exists', $this->tester->getErrorOutput());

        $this->flo(['make:migration', 'add_slug', '--table=posts']);
        $this->flo(['make:migration', 'cleanup']);
        foreach (glob($this->project->path('database/migrations') . '/*.php') ?: [] as $file) {
            $this->assertValidPhp('database/migrations/' . basename($file));
        }
    }

    public function testProjectStubsOverrideThePackageStubs(): void
    {
        self::assertSame(0, $this->flo(['stub:publish', 'model']));
        self::assertFileExists($this->project->path('stubs/model.stub'));
        self::assertFileDoesNotExist($this->project->path('stubs/controller.stub'));
        $this->project->write('stubs/model.stub', "<?php\n// custom {{ class }} on {{ table }}\n");

        $this->flo(['make:model', 'Post']);

        self::assertSame("<?php\n// custom PostModel on posts\n", $this->project->read('models/PostModel.php'));

        self::assertSame(0, $this->flo(['stub:publish']));
        self::assertCount(count(Stubs::names()), glob($this->project->path('stubs') . '/*.stub') ?: []);
        self::assertStringContainsString('custom', $this->project->read('stubs/model.stub'));
        self::assertSame(2, $this->flo(['stub:publish', 'nope']));
    }

    public function testInvalidNamesAreRejected(): void
    {
        self::assertSame(2, $this->flo(['make:controller', '../../escaped']));
        self::assertSame(2, $this->flo(['make:view', 'Blog', '../x']));
        self::assertSame(2, $this->flo(['make:module', 'a/b']));
        self::assertSame(2, $this->flo(['make:command', 'X', '--command=Bad Name']));
        self::assertDirectoryDoesNotExist($this->project->path('controllers'));
    }

    public function testDeleteAsksAndShowsTheFiles(): void
    {
        $this->flo(['make:route', 'Blog']);
        $this->flo(['make:view', 'Blog', 'show']);

        self::assertSame(1, $this->flo(['delete:view', 'Blog', 'show'], ['no']));
        self::assertStringContainsString('delete views/blog/show.html', $this->tester->getDisplay());
        self::assertStringContainsString('edit   controllers/BlogController.php (remove the show() action)', $this->tester->getDisplay());
        self::assertFileExists($this->project->path('views/blog/show.html'));

        self::assertSame(0, $this->flo(['delete:view', 'Blog', 'show'], ['yes']));
        self::assertFileDoesNotExist($this->project->path('views/blog/show.html'));
        $code = $this->project->read('controllers/BlogController.php');
        self::assertStringNotContainsString('function show', $code);
        self::assertStringContainsString('function index', $code);
        $this->assertValidPhp('controllers/BlogController.php');
    }

    public function testDeleteWithoutInteractionNeedsForce(): void
    {
        $this->flo(['make:controller', 'Blog']);

        self::assertSame(1, $this->tester->run(['delete:controller', 'Blog'], [], ['interactive' => false]));
        self::assertStringContainsString('pass --force', $this->tester->getErrorOutput());
        self::assertSame(0, $this->flo(['remove:controller', 'Blog', '--dry-run']));
        self::assertFileExists($this->project->path('controllers/BlogController.php'));

        self::assertSame(0, $this->flo(['delete:controller', 'Blog', '--force']));
        self::assertFileDoesNotExist($this->project->path('controllers/BlogController.php'));
        self::assertSame(1, $this->flo(['delete:controller', 'Blog', '--force']));
    }

    public function testDeleteModelUnwiresTheController(): void
    {
        $this->flo(['make:controller', 'Shop', '--model']);

        self::assertSame(0, $this->flo(['delete:model', 'Shop', '--force']));

        $code = $this->project->read('controllers/ShopController.php');
        self::assertStringNotContainsString('ShopModel', $code);
        $this->assertValidPhp('controllers/ShopController.php');
    }

    public function testDeleteRouteRemovesEverything(): void
    {
        $this->flo(['make:route', 'Blog']);
        $this->flo(['make:view', 'Blog', 'edit', '--admin']);

        self::assertSame(0, $this->flo(['delete:route', 'Blog', '--force']));

        self::assertFileDoesNotExist($this->project->path('controllers/BlogController.php'));
        self::assertFileDoesNotExist($this->project->path('models/BlogModel.php'));
        self::assertDirectoryDoesNotExist($this->project->path('views/blog'));
    }

    public function testOneXControllerWiringIsRemoved(): void
    {
        $this->project->write('controllers/OldController.php', "<?php\nnamespace FloCMS\\Controllers;\n\nuse FloCMS\\Core\\Controller;\nuse FloCMS\\Models\\OldModel;\n\nclass OldController extends Controller{\n\tpublic function __construct(array \$data = array())\n\t{\n\t\tparent::__construct(\$data);\n\t\t\$this->model = new OldModel();\n\t}\n}");
        $this->project->write('models/OldModel.php', '<?php');

        $this->flo(['delete:model', 'Old', '--force']);

        $code = $this->project->read('controllers/OldController.php');
        self::assertStringNotContainsString('OldModel', $code);
        self::assertStringContainsString('parent::__construct($data);', $code);
    }
}
