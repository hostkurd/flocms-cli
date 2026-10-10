<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Project;
use FloCMS\CLI\Support\Filesystem;
use FloCMS\CLI\Support\Name;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Backlog A7: `create:controller ../../escaped` wrote outside the project.
 */
final class SafeNamesAndPathsTest extends TestCase
{
    #[DataProvider('studlyNames')]
    public function testStudlyCase(string $input, string $expected): void
    {
        self::assertSame($expected, Name::studly($input, 'name', 'Controller'));
    }

    public static function studlyNames(): array
    {
        return [
            ['blog', 'Blog'],
            ['blog-post', 'BlogPost'],
            ['blog_post', 'BlogPost'],
            ['blogPost', 'BlogPost'],
            ['BlogController', 'Blog'],
            ['APIKeys', 'APIKeys'],
        ];
    }

    public function testSnakeKebabAndLower(): void
    {
        self::assertSame('blog_post', Name::snake('BlogPost'));
        self::assertSame('api_keys', Name::snake('APIKeys'));
        self::assertSame('blog-post', Name::kebab('blog_post'));
        self::assertSame('blogpost', Name::lower('BlogPost'));
        self::assertSame('admin_edit', Name::action('admin_edit'));
    }

    #[DataProvider('unsafeNames')]
    public function testUnsafeNamesAreRejected(string $name): void
    {
        $this->expectException(InputException::class);
        Name::studly($name);
    }

    public static function unsafeNames(): array
    {
        return [['../../escaped'], ['..\\..\\escaped'], ['a/b'], ['C:\\x'], ['/etc/passwd'], ['1blog'], [''], ['blog post'], ['blog.php'], ["blog\0"], ["blog\n"]];
    }

    public function testViewNamesCannotContainPaths(): void
    {
        $this->expectException(InputException::class);
        Name::action('../index');
    }

    #[DataProvider('outsidePaths')]
    public function testPathsOutsideTheProjectAreRefused(string $path): void
    {
        $project = new TempProject();
        try {
            $this->expectException(CommandFailedException::class);
            (new Filesystem(new Project($project->root)))->safePath($path);
        } finally {
            $project->remove();
        }
    }

    public static function outsidePaths(): array
    {
        return [
            ['../escaped.php'],
            ['controllers/../../escaped.php'],
            ['controllers\\..\\..\\escaped.php'],
            ['/etc/passwd'],
            ['C:\\Windows\\win.ini'],
            ['../../../../../../../../../../tmp/x'],
        ];
    }

    public function testPathsInsideTheProjectResolve(): void
    {
        $project = new TempProject();
        try {
            $fs = new Filesystem(new Project($project->root));

            self::assertSame($project->path('controllers/BlogController.php'), $fs->safePath('controllers/BlogController.php'));
            self::assertSame($project->path('views/blog/index.html'), $fs->safePath('views\\x\\..\\blog\\index.html'));
            self::assertSame($project->path('models/A.php'), $fs->safePath($project->path('models/A.php')));

            $fs->write('views/blog/index.html', 'x');
            self::assertSame('x', $fs->read('views/blog/index.html'));
            self::assertTrue($fs->delete('views/blog/index.html'));
            $fs->deleteEmptyDirectory('views/blog');
            self::assertDirectoryDoesNotExist($project->path('views/blog'));
        } finally {
            $project->remove();
        }
    }

    public function testSymlinkedDirectoriesCannotLeadOutside(): void
    {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Symlinks need special rights on Windows.');
        }
        $project = new TempProject();
        $outside = new TempProject();
        try {
            symlink($outside->root, $project->path('link'));

            $this->expectException(CommandFailedException::class);
            (new Filesystem(new Project($project->root)))->safePath('link/escaped.php');
        } finally {
            @unlink($project->path('link'));
            $project->remove();
            $outside->remove();
        }
    }

    public function testNormalize(): void
    {
        self::assertSame('C:/xampp/htdocs/site/views', Filesystem::normalize('C:\\xampp\\htdocs\\site\\controllers\\..\\views\\'));
        self::assertSame('/var/www/site', Filesystem::normalize('/var/www/./site/'));
        self::assertSame('/../x', Filesystem::normalize('/../x'));
    }

    public function testGeneratorsRefuseEscapingNamesWithExitCodeTwo(): void
    {
        $project = new TempProject();
        try {
            $result = $project->flo(['create:controller', '../../escaped']);

            self::assertSame(2, $result['code']);
            self::assertStringContainsString('Invalid controller name "../../escaped"', $result['stderr']);
            self::assertFileDoesNotExist(dirname($project->root) . DIRECTORY_SEPARATOR . 'escapedController.php');
            self::assertDirectoryDoesNotExist($project->path('controllers'));
        } finally {
            $project->remove();
        }
    }
}
