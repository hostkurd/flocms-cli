<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\App;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class ServeCommand extends Command
{
    protected string $name = 'serve';

    protected string $description = 'Run the site with PHP\'s built-in web server (development only)';

    protected string $help = <<<'TXT'
Serves public/ with a router that mirrors public/.htaccess (/api/v1 -> api.php,
static files, everything else -> index.php). When the port is taken, the next
free port is used. Stop with Ctrl+C. Not for production.
TXT;

    protected array $examples = [
        'php flo serve',
        'php flo serve --port=8080',
        'php flo serve --host=0.0.0.0   # reachable from your network',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('host', null, Option::VALUE_REQUIRED, 'The address to listen on', '127.0.0.1')
            ->option('port', 'p', Option::VALUE_REQUIRED, 'The port', '8000')
            ->option('tries', null, Option::VALUE_REQUIRED, 'How many following ports to try when the port is taken', '10');
    }

    protected function handle(): int
    {
        $host = (string) $this->option('host');
        if (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^[A-Za-z0-9.-]+$/D', $host) !== 1) {
            $this->invalid('Invalid host "' . $host . '".');
        }
        foreach (['port', 'tries'] as $name) {
            if (!ctype_digit((string) $this->option($name))) {
                $this->invalid('--' . $name . ' must be a number.');
            }
        }
        $port = (int) $this->option('port');
        if ($port < 1 || $port > 65535) {
            $this->invalid('--port must be between 1 and 65535.');
        }

        $public = $this->project()->path('public');
        if (!is_file($public . DIRECTORY_SEPARATOR . 'index.php')) {
            $this->fail('No public/index.php in ' . $this->project()->root() . '.');
        }

        $free = $this->freePort($host, $port, (int) $this->option('tries'));
        if ($free === null) {
            $this->fail('Ports ' . $port . '-' . ($port + (int) $this->option('tries')) . ' are all in use. Choose another with --port.');
        }

        $router = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'router.php';
        $this->success('Serving ' . $this->project()->root() . ' at http://' . $host . ':' . $free . ' (Ctrl+C to stop)');

        $process = proc_open([PHP_BINARY, '-S', $host . ':' . $free, '-t', $public, $router], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $this->project()->root());
        if (!is_resource($process)) {
            $this->fail('Could not start the PHP built-in server.');
        }

        return proc_close($process);
    }

    private function freePort(string $host, int $port, int $tries): ?int
    {
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $probe = $host === '0.0.0.0' ? '127.0.0.1' : ($host === '::' ? '[::1]' : $address);
        for ($candidate = $port; $candidate <= min(65535, $port + $tries); $candidate++) {
            // Windows lets a second socket bind a port in use, so also try to connect
            $client = @stream_socket_client('tcp://' . $probe . ':' . $candidate, $errno, $error, 0.2);
            if ($client !== false) {
                fclose($client);
                $this->line('<muted>Port ' . $candidate . ' is in use.</muted>');
                continue;
            }
            $socket = @stream_socket_server('tcp://' . $address . ':' . $candidate, $errno, $error);
            if ($socket !== false) {
                fclose($socket);

                return $candidate;
            }
            $this->line('<muted>Port ' . $candidate . ' is in use.</muted>');
        }

        return null;
    }
}
