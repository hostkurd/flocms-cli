<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Support\Maintenance;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Backlog B3: about, doctor, env:check, down/up, serve.
 */
final class AppCommandsTest extends TestCase
{
    private TempProject $project;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->skeleton();
        $this->tester = ApplicationTester::forProject($this->project->root);
    }

    protected function tearDown(): void
    {
        \FloCMS\Core\App::$db = null;
        $this->project->remove();
    }

    private function env(string $key, string $value): void
    {
        $env = $this->project->read('.env');
        $env = preg_match('/^' . $key . '=/m', $env) === 1
            ? (string) preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $env)
            : $env . $key . '=' . $value . "\n";
        $this->project->write('.env', $env);
        $this->tester = ApplicationTester::forProject($this->project->root);
    }

    public function testAbout(): void
    {
        self::assertSame(0, $this->tester->run(['about']));
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Environment  local', $display);
        self::assertStringContainsString('Version       ' . PHP_VERSION, $display);
        self::assertStringContainsString('flocms-core', $display);
        self::assertStringContainsString('Driver      sqlite', $display);

        $this->tester->run(['about', '--format=json']);
        $data = json_decode($this->tester->getDisplay(), true);
        self::assertSame(['Environment', 'PHP', 'Versions', 'Database', 'Paths'], array_keys($data));
        self::assertTrue($data['Environment']['Debug']);
        self::assertSame($this->project->root, $data['Paths']['Root']);
    }

    public function testDoctorOnAHealthyInstall(): void
    {
        $this->tester->run(['doctor']);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('[ OK ] PHP version', $display);
        self::assertStringContainsString('[ OK ] .env file', $display);
        self::assertStringContainsString('[ OK ] APP_KEY', $display);
        self::assertStringContainsString('[ OK ] Database', $display);
        self::assertStringContainsString('[ OK ] public/.htaccess', $display);

        $missing = array_filter(['pdo', 'mbstring', 'fileinfo', 'gd', 'pdo_sqlite'], static fn (string $e): bool => !extension_loaded($e));
        if ($missing === []) {
            self::assertSame(0, $this->tester->getStatusCode(), $display . $this->tester->getErrorOutput());
            self::assertStringContainsString('All checks passed', $display);
        }
    }

    public function testDoctorReportsProblems(): void
    {
        $this->env('APP_KEY', '');
        $this->env('APP_ENV', 'production');
        $this->env('APP_URL', 'not a url');
        unlink($this->project->path('public/.htaccess'));
        rmdir($this->project->path('views/cache'));

        self::assertSame(1, $this->tester->run(['doctor']));
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('[FAIL] APP_KEY', $display);
        self::assertStringContainsString('run php flo key:generate', $display);
        self::assertStringContainsString('[FAIL] APP_DEBUG', $display);
        self::assertStringContainsString('[FAIL] APP_URL', $display);
        self::assertStringContainsString('[FAIL] public/.htaccess', $display);
        self::assertStringContainsString('[FAIL] views/cache/', $display);

        $this->tester->run(['doctor', '--format=json']);
        $json = json_decode($this->tester->getDisplay(), true);
        self::assertFalse($json['ok']);
        self::assertGreaterThanOrEqual(5, $json['failed']);
    }

    public function testDoctorWarnsAboutPendingMigrationsAndAMissingDatabase(): void
    {
        $this->project->write('database/migrations/2026_01_01_000000_x.php', '<?php return ["up" => ["CREATE TABLE x (id INTEGER)"]];');
        $this->tester->run(['doctor']);
        self::assertStringContainsString('[WARN] Migrations', $this->tester->getDisplay());
        self::assertStringContainsString('1 pending', $this->tester->getDisplay());

        $this->env('DB_NAME', '');
        $this->tester->run(['doctor']);
        self::assertStringContainsString('[WARN] Database', $this->tester->getDisplay());
    }

    public function testEnvCheck(): void
    {
        self::assertSame(0, $this->tester->run(['env:check']));
        self::assertStringContainsString('.env has every key from .env.example', $this->tester->getDisplay());

        $this->project->write('.env.example', $this->project->read('.env.example') . "NEW_SETTING=\n# COMMENTED=\nexport EXPORTED=1\n");
        $this->project->write('.env', $this->project->read('.env') . "LOCAL_ONLY=1\n");
        self::assertSame(1, $this->tester->run(['env:check']));
        self::assertStringContainsString('NEW_SETTING', $this->tester->getErrorOutput());
        self::assertStringContainsString('EXPORTED', $this->tester->getErrorOutput());
        self::assertStringNotContainsString('COMMENTED', $this->tester->getErrorOutput());
        self::assertStringContainsString('Only in .env: LOCAL_ONLY', $this->tester->getDisplay());
    }

    public function testDownAndUp(): void
    {
        self::assertSame(0, $this->tester->run(['down', '--message=Back at 10', '--retry=600', '--allow=203.0.113.7', '--allow=10.0.0.0/8,::1']));
        $state = (new Maintenance(new \FloCMS\CLI\Project($this->project->root)))->read();
        self::assertSame('Back at 10', $state['message']);
        self::assertSame(600, $state['retry']);
        self::assertSame(['203.0.113.7', '10.0.0.0/8', '::1'], $state['allow']);
        // No settings table in this database: a warning, the file still applies
        self::assertStringContainsString('offline_mode setting was not changed', $this->tester->getErrorOutput());

        $this->tester->run(['about']);
        self::assertStringContainsString('Maintenance  yes', $this->tester->getDisplay());

        self::assertSame(0, $this->tester->run(['up']));
        self::assertFileDoesNotExist($this->project->path(Maintenance::FILE));
        self::assertSame(0, $this->tester->run(['up']));
        self::assertStringContainsString('was not in maintenance mode', $this->tester->getDisplay());
    }

    public function testDownValidatesItsOptions(): void
    {
        self::assertSame(2, $this->tester->run(['down', '--allow=300.1.1.1']));
        self::assertSame(2, $this->tester->run(['down', '--allow=10.0.0.0/33']));
        self::assertSame(2, $this->tester->run(['down', '--retry=soon']));
        self::assertFileDoesNotExist($this->project->path(Maintenance::FILE));
    }

    public function testDownAndUpToggleTheOfflineModeSetting(): void
    {
        $pdo = new \PDO('sqlite:' . $this->project->path('storage/database.sqlite'));
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, param VARCHAR(100), value TEXT, lang VARCHAR(5))');

        $this->tester->run(['down']);
        self::assertSame([['en', '1'], ['ar', '1']], $pdo->query("SELECT lang, value FROM settings WHERE param = 'offline_mode' ORDER BY id")->fetchAll(\PDO::FETCH_NUM));

        $this->tester->run(['up']);
        self::assertSame(['0', '0'], $pdo->query("SELECT value FROM settings WHERE param = 'offline_mode'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame('', $this->tester->getErrorOutput());
    }

    public function testServe(): void
    {
        $this->project->launcher();
        [$process, $url] = $this->serve(self::freePort());
        try {
            self::assertSame('home:/blog/show?x=1', self::get($url . '/blog/show?x=1'));
            self::assertSame('{"api":"\/api\/v1\/health"}', self::get($url . '/api/v1/health'));
            self::assertSame("User-agent: *\n", self::get($url . '/robots.txt'));
            self::assertStringStartsWith('home:', self::get($url . '/../.env'));
        } finally {
            self::stop($process);
        }
    }

    public function testServeMovesOnWhenThePortIsTaken(): void
    {
        $this->project->launcher();
        $blocker = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($blocker, false);
        $taken = (int) substr($name, (int) strrpos($name, ':') + 1);

        [$process, $url, $output] = $this->serve($taken);
        try {
            self::assertStringContainsString('Port ' . $taken . ' is in use.', $output);
            self::assertStringNotContainsString(':' . $taken, $url);
            self::assertSame('home:/', self::get($url . '/'));
        } finally {
            self::stop($process);
            fclose($blocker);
        }
    }

    /**
     * Start `php flo serve` and read the URL it reports.
     *
     * @return array{0: resource, 1: string, 2: string}
     */
    private function serve(int $port): array
    {
        $process = proc_open([PHP_BINARY, 'flo', 'serve', '--port=' . $port, '--no-ansi'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->project->root);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 10);
        $output = '';
        while (($line = fgets($pipes[1])) !== false) {
            $output .= $line;
            if (preg_match('#at (http://\S+) #', $line, $match) === 1) {
                return [$process, $match[1], $output];
            }
        }
        self::stop($process);
        self::fail("serve did not start:\n" . $output . stream_get_contents($pipes[2]));
    }

    public function testServeValidatesOptions(): void
    {
        self::assertSame(2, $this->tester->run(['serve', '--port=99999']));
        self::assertSame(2, $this->tester->run(['serve', '--host=bad host']));
        unlink($this->project->path('public/index.php'));
        self::assertSame(1, $this->tester->run(['serve']));
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function get(string $url): string
    {
        $deadline = microtime(true) + 10;
        do {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]));
            if ($body !== false) {
                return $body;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        self::fail('No response from ' . $url);
    }

    /** @param resource $process */
    private static function stop($process): void
    {
        $pid = proc_get_status($process)['pid'];
        if (DIRECTORY_SEPARATOR === '\\') {
            exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
        } else {
            exec('pkill -TERM -P ' . $pid . ' 2>/dev/null');
            proc_terminate($process);
        }
        proc_close($process);
    }
}
