<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class LangCommandsTest extends TestCase
{
    private TempProject $project;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->write('.env', "LANGUAGES=en,ar,ku\n");
        $this->project->write('lang/en.php', "<?php\nreturn array(\n    'lng.dir'=>'ltr',\n    'website.title' => 'Flo CMS',\n    'auth.throttled' => 'Too many login attempts. Please try again in :minutes minute(s).',\n);");
        $this->project->write('lang/ar.php', "<?php\nreturn ['lng.dir' => 'rtl', 'website.title' => 'فلو', 'old.key' => 'x'];");
        $this->project->write('lang/ku.php', "<?php\nreturn ['lng.dir' => 'rtl', 'website.title' => '', 'auth.throttled' => 'زۆر'];");
        $this->tester = ApplicationTester::forProject($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testMissingKeysInBothDirections(): void
    {
        self::assertSame(1, $this->tester->run(['lang:missing']));
        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/ar \(compared with en\)\n-+\n  missing  auth\.throttled  Too many login attempts\. Please try again in :.../u', $display);
        self::assertStringContainsString('extra    old.key (not in en)', $display);
        self::assertStringContainsString('empty    website.title', $display);
        self::assertStringContainsString('1 missing translation key(s) or file(s).', $this->tester->getErrorOutput());

        self::assertSame(0, $this->tester->run(['lang:missing', '--lang=ku']));
        self::assertStringNotContainsString('ar (', $this->tester->getDisplay());
    }

    public function testLanguagesSettingIsCheckedAgainstTheFiles(): void
    {
        $this->project->write('.env', "LANGUAGES=en,ar,fr\n");
        $this->project->write('lang/ar.php', "<?php\nreturn ['lng.dir' => 'rtl', 'website.title' => 'x', 'auth.throttled' => 'y'];");
        $tester = ApplicationTester::forProject($this->project->root);

        self::assertSame(1, $tester->run(['lang:missing']));
        self::assertStringContainsString('LANGUAGES lists "fr" but lang/fr.php does not exist', $tester->getErrorOutput());
        self::assertStringContainsString('lang/ku.php exists but "ku" is not in LANGUAGES.', $tester->getDisplay());

        $tester->run(['lang:missing', '--format=json']);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['fr'], $json['configured_without_file']);
        self::assertSame(['ku'], $json['files_not_configured']);
    }

    public function testAddCopiesTheKeysWithEmptyValues(): void
    {
        self::assertSame(0, $this->tester->run(['lang:add', 'fa', '--rtl']));
        self::assertStringContainsString('Created lang/fa.php with 3 keys to translate.', $this->tester->getDisplay());
        self::assertStringContainsString('Add "fa" to LANGUAGES', $this->tester->getDisplay());
        $fa = require $this->project->path('lang/fa.php');
        self::assertSame(['lng.dir' => 'rtl', 'website.title' => '', 'auth.throttled' => ''], $fa);

        self::assertSame(1, $this->tester->run(['lang:add', 'fa']));
        self::assertSame(0, $this->tester->run(['lang:add', 'fa', '--force']));
        self::assertSame('ltr', (require $this->project->path('lang/fa.php'))['lng.dir']);
        self::assertSame(0, $this->tester->run(['lang:add', 'pt_BR']));
        self::assertSame(2, $this->tester->run(['lang:add', '../x']));
        self::assertSame(1, $this->tester->run(['lang:add', 'de', '--base=xx']));
    }
}
