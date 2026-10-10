<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands\App;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Support\Maintenance;

final class UpCommand extends Command
{
    protected string $name = 'up';

    protected string $description = 'Bring the site out of maintenance mode';

    protected function handle(): int
    {
        $maintenance = new Maintenance($this->project());
        $wasDown = $maintenance->up();
        $setting = OfflineSetting::set($this->project(), false);
        if ($setting['warning'] !== null) {
            $this->warn($setting['warning']);
        }
        $settingChanged = $setting['changed'];

        if (!$wasDown && !$settingChanged) {
            $this->info('The site was not in maintenance mode.');

            return self::SUCCESS;
        }
        $this->success('The site is live again.');

        return self::SUCCESS;
    }
}
