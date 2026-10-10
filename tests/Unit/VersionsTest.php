<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Kernel;
use FloCMS\CLI\Project;
use FloCMS\CLI\Support\Versions;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Backlog A9: versions come from Composer, not hard-coded strings.
 */
final class VersionsTest extends TestCase
{
    public function testReadsInstalledPackageVersions(): void
    {
        $core = Versions::package(Versions::CORE);

        self::assertNotNull($core, 'flocms-core is a dependency');
        self::assertSame(Versions::clean(\Composer\InstalledVersions::getPrettyVersion(Versions::CORE)), $core);
        self::assertNull(Versions::package('nobody/not-installed'));
        self::assertNotSame('', Versions::cli());
    }

    public function testCleansVersionPrefixes(): void
    {
        self::assertSame('2.0.0', Versions::clean('v2.0.0'));
        self::assertSame('dev-main', Versions::clean('dev-main'));
        self::assertNull(Versions::clean(''));
    }

    public function testAppVersionFallsBackToEnv(): void
    {
        $project = new TempProject();
        try {
            $project->write('.env', "APP_VERSION=1.7.0\nAPP_ENV=local\nAPP_DEBUG=true\n");
            $site = new Project($project->root);

            self::assertSame('1.7.0', Versions::app($site));
            self::assertSame('1.7.0', Versions::framework($site)['app']);

            $header = implode("\n", Kernel::header($site));
            self::assertStringContainsString('FloCMS CLI', $header);
            self::assertStringContainsString('flocms-core', $header);
            self::assertStringContainsString('app <comment>1.7.0</comment>', $header);
            self::assertStringContainsString('Environment: <comment>local</comment>, <comment>debug on</comment>', $header);

            $result = $project->flo(['-V']);
            self::assertSame(0, $result['code']);
            self::assertMatchesRegularExpression('/^FloCMS CLI \S+ \(core \S+, app 1\.7\.0\)$/', trim($result['stdout']));
        } finally {
            $project->remove();
        }
    }
}
