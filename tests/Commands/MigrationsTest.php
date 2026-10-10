<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Database\Migrator;
use FloCMS\CLI\Project;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Backlog B2 on SQLite. MysqlMigrationsTest runs the same flow on MySQL in CI.
 */
final class MigrationsTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->sqlite();
    }

    protected function tearDown(): void
    {
        \FloCMS\Core\App::$db = null;
        $this->project->remove();
    }

    private function tester(): ApplicationTester
    {
        return ApplicationTester::forProject($this->project->root);
    }

    private function migration(string $name, string $table, bool $failing = false): void
    {
        $this->project->write('database/migrations/' . $name . '.php', '<?php
use FloCMS\CLI\Database\Migration;
use FloCMS\Core\Database;
return new class extends Migration {
    public function up(Database $db): void {
        $db->query("CREATE TABLE ' . $table . ' (" . $this->id($db) . ", name VARCHAR(100))" . $this->tableOptions($db));
        ' . ($failing ? '$db->query("INSERT INTO no_such_table VALUES (1)");' : '') . '
    }
    public function down(Database $db): void { $db->query("DROP TABLE ' . $table . '"); }
};');
    }

    private function tables(): array
    {
        $pdo = new \PDO('sqlite:' . $this->project->path('storage/database.sqlite'));

        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testMigrateRunsAppAndPackageMigrationsInOrder(): void
    {
        $this->migration('2026_01_01_000000_create_posts_table', 'posts');
        $this->migration('2026_03_01_000000_create_tags_table', 'tags');
        $this->project->write('vendor/acme/blog/database/migrations/2026_02_01_000000_create_comments_table.php', '<?php
return ["up" => ["CREATE TABLE comments (id INTEGER PRIMARY KEY, body TEXT)"], "down" => ["DROP TABLE comments"]];');
        $this->project->installed([['name' => 'acme/blog', 'install-path' => '../acme/blog', 'extra' => ['flocms' => ['migrations' => ['database/migrations']]]]]);

        $tester = $this->tester();
        self::assertSame(0, $tester->run(['migrate']), $tester->getErrorOutput());
        $display = $tester->getDisplay();
        self::assertStringContainsString('3 migration(s) ran (batch 1).', $display);
        self::assertLessThan(strpos($display, 'acme/blog:2026_02_01'), strpos($display, '2026_01_01_000000_create_posts_table'));
        self::assertLessThan(strpos($display, '2026_03_01'), strpos($display, 'acme/blog:2026_02_01'));
        self::assertSame(['comments', 'migrations', 'posts', 'tags'], $this->tables());

        self::assertSame(0, $tester->run(['migrate']));
        self::assertStringContainsString('Nothing to migrate.', $tester->getDisplay());

        self::assertSame(0, $tester->run(['migrate:status']));
        self::assertMatchesRegularExpression('/2026_02_01_000000_create_comments_table +\| acme\/blog +\| 1 +\| Ran/', $tester->getDisplay());
        self::assertStringContainsString('Everything has run.', $tester->getDisplay());
    }

    public function testBatchesRollbackAndStep(): void
    {
        $this->migration('2026_01_01_000000_create_a_table', 'a');
        $tester = $this->tester();
        $tester->run(['migrate']);
        $this->migration('2026_01_02_000000_create_b_table', 'b');
        $this->migration('2026_01_03_000000_create_c_table', 'c');
        $tester->run(['migrate']);
        self::assertSame(['a', 'b', 'c', 'migrations'], $this->tables());

        self::assertSame(0, $tester->run(['migrate:rollback', '--pretend']));
        self::assertStringContainsString('2026_01_03_000000_create_c_table', $tester->getDisplay());
        self::assertSame(['a', 'b', 'c', 'migrations'], $this->tables());

        self::assertSame(0, $tester->run(['migrate:rollback']));
        self::assertStringContainsString('2 migration(s) rolled back.', $tester->getDisplay());
        self::assertSame(['a', 'migrations'], $this->tables());

        $tester->run(['migrate']);
        self::assertSame(0, $tester->run(['migrate:rollback', '--step=1']));
        self::assertSame(['a', 'b', 'migrations'], $this->tables());
        self::assertSame(2, $tester->run(['migrate:rollback', '--step=0']));

        $tester->run(['migrate:rollback', '--step=5']);
        self::assertSame(['migrations'], $this->tables());
        self::assertSame(0, $tester->run(['migrate:rollback']));
        self::assertStringContainsString('Nothing to roll back.', $tester->getDisplay());
    }

    public function testAFailingMigrationRollsBackAndIsNotRecorded(): void
    {
        $this->migration('2026_01_01_000000_create_ok_table', 'ok');
        $this->migration('2026_01_02_000000_create_bad_table', 'bad', true);
        $tester = $this->tester();

        self::assertSame(1, $tester->run(['migrate']));
        self::assertStringContainsString('Migration app:2026_01_02_000000_create_bad_table failed', $tester->getErrorOutput());
        self::assertSame(['migrations', 'ok'], $this->tables());

        $tester->run(['migrate:status']);
        self::assertMatchesRegularExpression('/create_bad_table +\| app +\| +\| Pending/', $tester->getDisplay());
    }

    public function testStatusFlagsChangedAndMissingFiles(): void
    {
        $this->migration('2026_01_01_000000_create_x_table', 'x');
        $this->migration('2026_01_02_000000_create_y_table', 'y');
        $tester = $this->tester();
        $tester->run(['migrate']);

        file_put_contents($this->project->path('database/migrations/2026_01_01_000000_create_x_table.php'), "\n// edited", FILE_APPEND);
        unlink($this->project->path('database/migrations/2026_01_02_000000_create_y_table.php'));

        $tester->run(['migrate:status']);
        self::assertStringContainsString('Ran (changed since)', $tester->getDisplay());
        self::assertStringContainsString('Ran (file missing)', $tester->getDisplay());
        self::assertSame(1, $tester->run(['migrate:rollback']));
        self::assertStringContainsString('its migration file is missing', $tester->getErrorOutput());
    }

    public function testProductionAsksAndNoInteractionNeedsForce(): void
    {
        $this->project->sqlite("APP_ENV=production\n");
        $this->project->write('.env', str_replace('APP_ENV=local', 'APP_ENV=production', $this->project->read('.env')));
        $this->migration('2026_01_01_000000_create_p_table', 'p');
        $tester = $this->tester();

        self::assertSame(1, $tester->run(['migrate'], [], ['interactive' => false]));
        self::assertStringContainsString('Pass --force', $tester->getErrorOutput());
        self::assertSame(1, $tester->run(['migrate'], ['no']));
        self::assertSame([], $this->tables());

        self::assertSame(0, $tester->run(['migrate'], ['yes']));
        self::assertSame(['migrations', 'p'], $this->tables());
        self::assertSame(1, $tester->run(['migrate:fresh'], ['yes']));
        self::assertStringContainsString('Refusing on a production site', $tester->getErrorOutput());
        self::assertSame(0, $tester->run(['migrate:rollback', '--force']));
    }

    public function testFreshDropsEverythingAndMigrates(): void
    {
        $this->migration('2026_01_01_000000_create_f_table', 'f');
        $tester = $this->tester();
        $tester->run(['migrate']);
        (new \PDO('sqlite:' . $this->project->path('storage/database.sqlite')))->exec('CREATE TABLE manual (id INTEGER)');

        self::assertSame(1, $tester->run(['migrate:fresh'], ['no']));
        self::assertContains('manual', $this->tables());
        self::assertSame(1, $tester->run(['migrate:fresh'], [], ['interactive' => false]));

        self::assertSame(0, $tester->run(['migrate:fresh'], ['yes']));
        self::assertSame(['f', 'migrations'], $this->tables());
        self::assertSame(0, $tester->run(['migrate:fresh', '--force']));
    }

    public function testSeeders(): void
    {
        $this->migration('2026_01_01_000000_create_people_table', 'people');
        $this->project->write('database/seeders/DatabaseSeeder.php', '<?php
namespace Database\Seeders;
use FloCMS\CLI\Database\Seeder;
use FloCMS\Core\Database;
final class DatabaseSeeder extends Seeder {
    public function run(Database $db): void { $db->table("people")->insert(["name" => "Ada"]); $this->call(PeopleSeeder::class); }
}');
        $this->project->write('database/seeders/PeopleSeeder.php', '<?php
namespace Database\Seeders;
use FloCMS\CLI\Database\Seeder;
use FloCMS\Core\Database;
final class PeopleSeeder extends Seeder {
    public function run(Database $db): void { $db->table("people")->insert(["name" => "Grace"]); }
}');
        $tester = $this->tester();

        self::assertSame(0, $tester->run(['migrate', '--seed']), $tester->getErrorOutput());
        self::assertStringContainsString('Seeding DatabaseSeeder', $tester->getDisplay());
        self::assertStringContainsString('Seeding PeopleSeeder', $tester->getDisplay());
        $pdo = new \PDO('sqlite:' . $this->project->path('storage/database.sqlite'));
        self::assertSame(['Ada', 'Grace'], $pdo->query('SELECT name FROM people ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));

        self::assertSame(0, $tester->run(['db:seed', '--class=PeopleSeeder']));
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM people')->fetchColumn());
        self::assertSame(1, $tester->run(['db:seed', '--class=Nope']));
        self::assertStringContainsString('php flo make:seeder Nope', $tester->getErrorOutput());
    }

    public function testDbCheckAndShow(): void
    {
        $this->migration('2026_01_01_000000_create_s_table', 's');
        $tester = $this->tester();
        $tester->run(['migrate']);

        self::assertSame(0, $tester->run(['db:check']));
        self::assertStringContainsString('[OK] Connected to', $tester->getDisplay());
        self::assertStringContainsString('(sqlite, ', $tester->getDisplay());

        self::assertSame(0, $tester->run(['db:show']));
        self::assertMatchesRegularExpression('/\| s +\| 0 +\|/', $tester->getDisplay());
        $tester->run(['db:show', '--format=json']);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame('sqlite', $json['connection']['driver']);
        self::assertSame(['migrations', 's'], array_column($json['tables'], 'table'));
    }

    public function testNotConfiguredAndUnreachableDatabases(): void
    {
        $this->project->write('.env', "APP_ENV=local\nDB_NAME=\nDB_USERNAME=\n");
        $tester = $this->tester();
        self::assertSame(1, $tester->run(['db:check']));
        self::assertStringContainsString('not configured', $tester->getErrorOutput());
        self::assertSame(1, $tester->run(['migrate']));

        $this->project->write('.env', "APP_ENV=local\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_NAME=x\nDB_USERNAME=root\n");
        $tester = $this->tester();
        self::assertSame(1, $tester->run(['db:check']));
        self::assertStringContainsString('Cannot connect to the database (127.0.0.1:1/x)', $tester->getErrorOutput());
        self::assertStringContainsString('Is MySQL/MariaDB running', $tester->getErrorOutput());
    }

    public function testMigratorRefusesConcurrentRuns(): void
    {
        $this->migration('2026_01_01_000000_create_l_table', 'l');
        mkdir($this->project->path('storage/framework'), 0777, true);
        $lock = fopen($this->project->path('storage/framework/migrations.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $tester = $this->tester();
            self::assertSame(1, $tester->run(['migrate']));
            self::assertStringContainsString('Another migration is running', $tester->getErrorOutput());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testChecksumIgnoresLineEndings(): void
    {
        $a = $this->project->write('a.php', "<?php\nreturn [];\n");
        $b = $this->project->write('b.php', "<?php\r\nreturn [];\r\n");

        self::assertSame(Migrator::checksum($a), Migrator::checksum($b));
    }

    public function testFilesWithoutUpAreRejected(): void
    {
        $this->project->write('database/migrations/2026_01_01_000000_broken.php', '<?php return 42;');
        $tester = $this->tester();

        self::assertSame(1, $tester->run(['migrate']));
        self::assertStringContainsString('must return a Migration object', $tester->getErrorOutput());
        self::assertInstanceOf(Project::class, $tester->application()->project());
    }
}
