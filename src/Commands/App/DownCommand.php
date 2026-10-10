<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\App;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;
use FloCMS\CLI\Support\Maintenance;

final class DownCommand extends Command
{
    protected string $name = 'down';

    protected string $description = 'Put the site into maintenance mode (HTTP 503)';

    protected string $help = <<<'TXT'
Writes storage/framework/down.json; FloCMS 1.7+ answers every request with
503 (and Retry-After) until `php flo up`, except for the --allow addresses.
When the database is reachable, the offline_mode setting is turned on too.
TXT;

    protected array $examples = [
        'php flo down',
        'php flo down --message="Upgrading, back in 10 minutes" --retry=600',
        'php flo down --allow=203.0.113.7 --allow=10.0.0.0/8',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('message', 'm', Option::VALUE_REQUIRED, 'The message shown to visitors', 'The site is under maintenance. Please try again later.')
            ->option('retry', null, Option::VALUE_REQUIRED, 'Seconds clients should wait before retrying (Retry-After)')
            ->option('allow', 'a', Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY, 'IP address or CIDR range that keeps access (repeatable, or comma separated)');
    }

    protected function handle(): int
    {
        $retry = $this->option('retry');
        if ($retry !== null && (!ctype_digit((string) $retry) || (int) $retry < 1)) {
            $this->invalid('--retry must be a number of seconds.');
        }
        $allow = [];
        foreach ((array) $this->option('allow') as $value) {
            foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $range) {
                if (!Maintenance::isValidRange($range)) {
                    $this->invalid('Invalid IP address or CIDR range: ' . $range);
                }
                $allow[] = $range;
            }
        }

        $maintenance = new Maintenance($this->project());
        $already = $maintenance->isDown();
        $maintenance->down((string) $this->option('message'), $retry === null ? null : (int) $retry, array_values(array_unique($allow)));
        $setting = OfflineSetting::set($this->project(), true);
        if ($setting['warning'] !== null) {
            $this->warn($setting['warning']);
        }

        $this->success($already ? 'Maintenance mode updated.' : 'The site is now in maintenance mode.');
        if ($allow !== []) {
            $this->line('Still open for: ' . implode(', ', $allow));
        }
        $this->line('Run "php flo up" to bring it back.');

        return self::SUCCESS;
    }
}
