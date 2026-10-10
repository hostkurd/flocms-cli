<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Mysql;

use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Migrations on a real MySQL/MariaDB server (CI sets FLO_TEST_MYSQL_*).
 */
final class MysqlMigrationsTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        if (!$this->project->mysql()) {
            $this->project->remove();
            self::markTestSkipped('Set FLO_TEST_MYSQL_HOST (and _PORT, _USER, _PASS, _NAME) to run the MySQL tests.');
        }
        $tester = ApplicationTester::forProject($this->project->root);
        self::assertSame(0, $tester->run(['migrate:fresh', '--force']), $tester->getErrorOutput());
    }

    protected function tearDown(): void
    {
        \FloCMS\Core\App::$db = null;
        if (isset($this->project) && is_dir($this->project->root)) {
            $this->project->remove();
        }
    }

    public function testMigrateRollbackAndFresh(): void
    {
        $this->project->write('database/migrations/2026_01_01_000000_create_posts_table.php', (string) file_get_contents(dirname(__DIR__, 2) . '/resources/stubs/migration.create.stub'));
        $file = $this->project->path('database/migrations/2026_01_01_000000_create_posts_table.php');
        file_put_contents($file, str_replace('{{ table }}', 'posts', (string) file_get_contents($file)));

        $tester = ApplicationTester::forProject($this->project->root);
        self::assertSame(0, $tester->run(['migrate']), $tester->getErrorOutput());
        self::assertSame(0, $tester->run(['db:show']));
        self::assertMatchesRegularExpression('/\| posts +\| 0 +\|/', $tester->getDisplay());

        // DDL commits implicitly on MySQL; the transaction handling must cope
        self::assertSame(0, $tester->run(['migrate:rollback']), $tester->getErrorOutput());
        $tester->run(['db:show', '--format=json']);
        self::assertSame(['migrations'], array_column(json_decode($tester->getDisplay(), true)['tables'], 'table'));

        self::assertSame(0, $tester->run(['migrate']));
        self::assertSame(0, $tester->run(['db:check']));
        self::assertStringContainsString('(mysql, ', $tester->getDisplay());
    }
}
