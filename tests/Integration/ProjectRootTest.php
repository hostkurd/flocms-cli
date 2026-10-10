<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Integration;

use FloCMS\CLI\Project;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Backlog A6: the root comes from the launcher, not the current directory.
 */
final class ProjectRootTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testRunningFromASubfolderWritesToTheProjectRoot(): void
    {
        mkdir($this->project->path('sub/deeper'), 0777, true);

        $result = $this->project->flo(['create:model', 'Blog'], $this->project->path('sub/deeper'));

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($this->project->path('models/BlogModel.php'));
        self::assertDirectoryDoesNotExist($this->project->path('sub/deeper/models'));
    }

    public function testDetectWalksUpToTheFloCmsComposerJson(): void
    {
        mkdir($this->project->path('vendor/hostkurd/flocms-cli/bin'), 0777, true);
        $this->project->write('vendor/hostkurd/flocms-cli/composer.json', '{"name": "hostkurd/flocms-cli", "require": {"php": "^8.1"}}');

        self::assertSame($this->project->root, Project::detect($this->project->path('vendor/hostkurd/flocms-cli/bin')));
        self::assertSame($this->project->root, Project::fromLauncher($this->project->path('vendor/hostkurd/flocms-cli/bin'))->root());
        self::assertSame($this->project->root, Project::fromLauncher($this->project->root)->root());
    }

    public function testTheSkeletonItselfIsAProject(): void
    {
        $this->project->write('composer.json', '{"name": "hostkurd/flocms"}');

        self::assertTrue(Project::isProjectRoot($this->project->root));
    }

    public function testDirectoriesOutsideAProjectAreKeptAsGiven(): void
    {
        $dir = $this->project->path('loose');
        mkdir($dir);
        $this->project->write('composer.json', '{"name": "not/flocms"}');

        self::assertNull(Project::detect($dir));
        self::assertSame(realpath($dir), Project::fromLauncher($dir)->root());
    }

    public function testEnvReadsDotenvWithTheRealEnvironmentFirst(): void
    {
        $this->project->write('.env', "APP_ENV=staging\nAPP_DEBUG=false\nDB_PORT=3307\nAPP_NAME=\"My Site\"\n# comment\nEMPTY=\n");
        $project = new Project($this->project->root);

        self::assertSame('staging', $project->environment());
        self::assertFalse($project->env('APP_DEBUG'));
        self::assertSame(3307, $project->env('DB_PORT'));
        self::assertSame('My Site', $project->env('APP_NAME'));
        self::assertSame('', $project->env('EMPTY'));
        self::assertSame('x', $project->env('MISSING', 'x'));
        self::assertFalse($project->isProduction());

        putenv('APP_ENV=production');
        try {
            self::assertTrue($project->isProduction());
        } finally {
            putenv('APP_ENV');
        }
    }

    public function testRelativePaths(): void
    {
        $project = new Project($this->project->root);

        self::assertSame('models/BlogModel.php', $project->relative($project->path('models/BlogModel.php')));
        self::assertSame('/elsewhere/x.php', $project->relative('/elsewhere/x.php'));
    }
}
