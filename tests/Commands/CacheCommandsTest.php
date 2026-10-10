<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CacheCommandsTest extends TestCase
{
    private TempProject $project;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->skeleton();
        $this->project->write('views/cache/.gitignore', "*\n!.gitignore\n");
        $this->project->write('views/pages/index.html', '<h1><?= $title ?></h1> @csrf');
        $this->project->write('views/users/admin_index.html', '<p>Users</p>');
        $this->project->write('templates/default/layouts/default.html', '<html><?= $content ?></html>');
        $this->tester = ApplicationTester::forProject($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /** @return list<string> */
    private function compiled(): array
    {
        return array_map('basename', glob($this->project->path('views/cache') . '/*.php') ?: []);
    }

    public function testOptimizeCompilesEveryTemplate(): void
    {
        self::assertSame(0, $this->tester->run(['optimize']), $this->tester->getErrorOutput());
        self::assertStringContainsString('3 template(s) compiled into views/cache.', $this->tester->getDisplay());
        self::assertStringContainsString('composer dump-autoload --optimize', $this->tester->getDisplay());
        self::assertCount(3, $this->compiled());

        $index = (string) file_get_contents((string) current(glob($this->project->path('views/cache') . '/index_*.php')));
        self::assertStringContainsString('Csrf::field()', $index);

        // Running again reuses the compiled files
        $this->tester->run(['optimize']);
        self::assertCount(3, $this->compiled());
    }

    public function testViewClearKeepsGitignore(): void
    {
        $this->tester->run(['optimize']);

        self::assertSame(0, $this->tester->run(['view:clear']));
        self::assertStringContainsString('(3 file(s))', $this->tester->getDisplay());
        self::assertSame([], $this->compiled());
        self::assertFileExists($this->project->path('views/cache/.gitignore'));
        self::assertFileExists($this->project->path('views/pages/index.html'));
    }

    public function testCacheClearKeepsModuleCachesUnlessAll(): void
    {
        $this->project->write('storage/cache/api-rate-limit/abc.json', '{}');
        $this->project->write('storage/cache/login-throttle/x', '1');
        $this->project->write('storage/cache/modules.php', '<?php return [];');
        $this->project->write('storage/cache/modules-state.json', '{}');
        $this->project->write('storage/cache/.gitignore', '*');

        self::assertSame(0, $this->tester->run(['cache:clear']));
        self::assertStringContainsString('(2 file(s))', $this->tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->project->path('storage/cache/api-rate-limit'));
        self::assertFileExists($this->project->path('storage/cache/modules.php'));
        self::assertFileExists($this->project->path('storage/cache/.gitignore'));

        $this->tester->run(['cache:clear', '--all']);
        self::assertFileDoesNotExist($this->project->path('storage/cache/modules.php'));
        self::assertFileExists($this->project->path('storage/cache/.gitignore'));
    }

    public function testOptimizeClear(): void
    {
        $this->tester->run(['optimize']);
        $this->project->write('storage/cache/x.json', '{}');

        self::assertSame(0, $this->tester->run(['optimize:clear']));
        self::assertSame([], $this->compiled());
        self::assertFileDoesNotExist($this->project->path('storage/cache/x.json'));
    }

    public function testMissingDirectoriesAreFine(): void
    {
        $empty = new TempProject();
        try {
            $tester = ApplicationTester::forProject($empty->root);
            self::assertSame(0, $tester->run(['cache:clear']));
            self::assertSame(0, $tester->run(['view:clear']));
            self::assertSame(0, $tester->run(['optimize']));
            self::assertStringContainsString('0 template(s)', $tester->getDisplay());
        } finally {
            $empty->remove();
        }
    }
}
