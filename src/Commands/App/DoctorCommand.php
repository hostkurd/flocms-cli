<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\App;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Database\ConnectionFactory;
use FloCMS\CLI\Database\Migrator;
use FloCMS\CLI\Support\Maintenance;
use Throwable;

/**
 * Install health check, written for shared hosting (cPanel) installs.
 */
final class DoctorCommand extends Command
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    protected string $name = 'doctor';

    protected string $description = 'Check the installation: PHP, extensions, permissions, .env, database, web server';

    protected string $help = 'Exit code 1 when a check fails; warnings do not fail.';

    /** @var list<array{status: string, check: string, detail: string}> */
    private array $results = [];

    protected function configure(Definition $definition): void
    {
        $definition->option('format', null, Option::VALUE_REQUIRED, 'txt or json', 'txt');
    }

    /** @return list<array{status: string, check: string, detail: string}> */
    public function checks(): array
    {
        $this->results = [];
        $project = $this->project();

        // PHP and extensions
        $this->add(PHP_VERSION_ID >= 80100 ? self::OK : self::FAIL, 'PHP version', PHP_VERSION . (PHP_VERSION_ID >= 80100 ? '' : ' (8.1 or newer is required)'));
        foreach (['pdo' => true, 'mbstring' => true, 'fileinfo' => true, 'gd' => true, 'openssl' => false, 'curl' => false] as $extension => $required) {
            $loaded = extension_loaded($extension);
            $this->add($loaded ? self::OK : ($required ? self::FAIL : self::WARN), 'Extension ' . $extension, $loaded ? 'loaded' : 'missing' . ($required ? '' : ' (recommended)'));
        }
        $driver = ConnectionFactory::driver($project);
        $pdoDriver = $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        $this->add(extension_loaded($pdoDriver) ? self::OK : self::FAIL, 'Extension ' . $pdoDriver, extension_loaded($pdoDriver) ? 'loaded' : 'missing (needed for DB_TYPE=' . $driver . ')');

        // Files and permissions
        $this->add(is_file($project->path('vendor/autoload.php')) ? self::OK : self::FAIL, 'Composer dependencies', is_file($project->path('vendor/autoload.php')) ? 'installed' : 'missing: run composer install');
        foreach (['storage', 'storage/logs', 'storage/cache', 'views/cache'] as $directory) {
            $path = $project->path($directory);
            $status = is_dir($path) ? (is_writable($path) ? self::OK : self::FAIL) : ($directory === 'storage/cache' ? self::WARN : self::FAIL);
            $detail = is_dir($path) ? (is_writable($path) ? 'writable' : 'not writable by ' . self::user()) : 'missing';
            $this->add($status, $directory . '/', $detail);
        }

        // .env
        $hasEnv = $project->hasEnvFile();
        $this->add($hasEnv ? self::OK : self::FAIL, '.env file', $hasEnv ? 'present' : 'missing: copy .env.example to .env');
        if ($hasEnv && is_file($project->path('.env.example'))) {
            $missing = array_diff(EnvCheckCommand::keys($project, '.env.example'), EnvCheckCommand::keys($project, '.env'));
            $this->add($missing === [] ? self::OK : self::WARN, '.env keys', $missing === [] ? 'complete' : 'missing ' . implode(', ', $missing) . ' (php flo env:check)');
        }
        $key = (string) $project->env('APP_KEY', '');
        $this->add($key !== '' ? self::OK : self::FAIL, 'APP_KEY', $key !== '' ? 'set' : 'empty: run php flo key:generate');
        $url = (string) $project->env('APP_URL', '');
        $validUrl = filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1;
        $this->add($validUrl ? self::OK : self::FAIL, 'APP_URL', $url === '' ? 'empty' : ($validUrl ? $url : $url . ' is not an http(s) URL'));
        if ($validUrl && $project->isProduction() && str_starts_with(strtolower($url), 'http://')) {
            $this->add(self::WARN, 'HTTPS', 'APP_URL uses http:// on a production site');
        }
        $debug = $project->env('APP_DEBUG') === true;
        $this->add($debug && $project->isProduction() ? self::FAIL : self::OK, 'APP_DEBUG', $debug ? ($project->isProduction() ? 'true on a production site: set APP_DEBUG=false' : 'true (' . $project->environment() . ')') : 'false');

        // Database
        if (!ConnectionFactory::isConfigured($project)) {
            $this->add(self::WARN, 'Database', 'not configured (DB_NAME / DB_USERNAME in .env)');
        } else {
            try {
                $project->database()->query('SELECT 1');
                $this->add(self::OK, 'Database', 'connected (' . $driver . ')');
                $pending = count(Migrator::forProject($project)->pending());
                $this->add($pending === 0 ? self::OK : self::WARN, 'Migrations', $pending === 0 ? 'up to date' : $pending . ' pending: run php flo migrate');
            } catch (Throwable $error) {
                $this->add(self::FAIL, 'Database', strtok($error->getMessage(), "\n") ?: 'cannot connect');
            }
        }

        // Web server
        $rootRules = is_file($project->path('.htaccess')) ? (string) file_get_contents($project->path('.htaccess')) : '';
        $publicRules = is_file($project->path('public/.htaccess')) ? (string) file_get_contents($project->path('public/.htaccess')) : '';
        $this->add(str_contains($publicRules, 'RewriteRule') ? self::OK : self::FAIL, 'public/.htaccess', $publicRules === '' ? 'missing (Apache needs it to route requests to index.php)' : (str_contains($publicRules, 'RewriteRule') ? 'rewrite rules present' : 'no rewrite rules'));
        $this->add(str_contains($rootRules, 'public/') ? self::OK : self::WARN, 'Document root', str_contains($rootRules, 'public/')
            ? 'point the document root at public/ (the root .htaccess forwards there as a fallback)'
            : 'set the web server\'s document root to public/; without the root .htaccess, project files may be reachable');
        $this->add((new Maintenance($project))->isDown() ? self::WARN : self::OK, 'Maintenance mode', (new Maintenance($project))->isDown() ? 'on (php flo up)' : 'off');

        return $this->results;
    }

    protected function handle(): int
    {
        $results = $this->checks();
        $failed = count(array_filter($results, static fn (array $r): bool => $r['status'] === self::FAIL));
        $warnings = count(array_filter($results, static fn (array $r): bool => $r['status'] === self::WARN));

        if ($this->option('format') === 'json') {
            $this->output->write(json_encode(['ok' => $failed === 0, 'failed' => $failed, 'warnings' => $warnings, 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $width = max(array_map(static fn (array $r): int => strlen($r['check']), $results));
        foreach ($results as $result) {
            $badge = match ($result['status']) {
                self::OK => '<info>[ OK ]</info>',
                self::WARN => '<comment>[WARN]</comment>',
                default => '<error>[FAIL]</error>',
            };
            $this->line(' ' . $badge . ' ' . str_pad($result['check'], $width) . '  ' . Formatter::escape($result['detail']));
        }
        $this->newLine();
        if ($failed > 0) {
            $this->error($failed . ' check(s) failed, ' . $warnings . ' warning(s).');

            return self::FAILURE;
        }
        $this->success('All checks passed' . ($warnings > 0 ? ' (' . $warnings . ' warning(s))' : '') . '.');

        return self::SUCCESS;
    }

    private function add(string $status, string $check, string $detail): void
    {
        $this->results[] = ['status' => $status, 'check' => $check, 'detail' => $detail];
    }

    private static function user(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info)) {
                return (string) $info['name'];
            }
        }

        return (string) (get_current_user() ?: 'the PHP user');
    }
}
