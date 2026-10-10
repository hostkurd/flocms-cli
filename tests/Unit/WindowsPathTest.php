<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Discovery\PackageManifest;
use FloCMS\CLI\Project;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * XAMPP users run flo on Windows: paths may use either separator.
 */
final class WindowsPathTest extends TestCase
{
    public function testProjectPathsUseTheNativeSeparator(): void
    {
        $project = new Project('C:\\xampp\\htdocs\\site\\');

        self::assertSame('C:\\xampp\\htdocs\\site', $project->root());
        self::assertSame(
            'C:\\xampp\\htdocs\\site' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'pages',
            $project->path('views\\pages')
        );
        self::assertSame($project->path('views/pages'), $project->path('views\\pages'));
    }

    public function testInstallPathsWithBackslashesResolve(): void
    {
        $project = new TempProject();
        try {
            $project->installed([[
                'name' => 'acme/pkg',
                'install-path' => '..\\acme\\pkg',
                'extra' => ['flocms' => ['migrations' => ['database\\migrations']]],
            ]]);

            $migrations = (new PackageManifest($project->root))->migrations();

            self::assertSame([$project->path('vendor/acme/pkg/database/migrations')], $migrations['acme/pkg']);
        } finally {
            $project->remove();
        }
    }
}
