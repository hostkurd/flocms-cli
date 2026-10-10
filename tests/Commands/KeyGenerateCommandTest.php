<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Commands\App\KeyGenerateCommand;
use FloCMS\CLI\Console\Testing\CommandTester;
use FloCMS\CLI\Project;
use FloCMS\CLI\Support\KeyGenerator;
use FloCMS\CLI\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class KeyGenerateCommandTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new KeyGenerateCommand(), new Project($this->project->root));
    }

    public function testGeneratesA32ByteKey(): void
    {
        $key = KeyGenerator::generate();

        self::assertStringStartsWith('base64:', $key);
        self::assertSame(32, strlen((string) base64_decode(substr($key, 7), true)));
        self::assertNotSame($key, KeyGenerator::generate());
    }

    public function testFillsAnEmptyKeyOnly(): void
    {
        $this->project->write('.env', "APP_NAME=FloCMS\nAPP_KEY=\nAPP_URL=http://localhost\n");
        $tester = $this->tester();

        self::assertSame(0, $tester->execute());
        self::assertStringContainsString('APP_KEY set in .env.', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^APP_NAME=FloCMS\nAPP_KEY=base64:[A-Za-z0-9+\/=]{44}\nAPP_URL=http:\/\/localhost\n$/', $this->project->read('.env'));
        $env = $this->project->read('.env');

        self::assertSame(0, $tester->execute());
        self::assertStringContainsString('APP_KEY is already set. Use --force to replace it.', $tester->getDisplay());
        self::assertSame($env, $this->project->read('.env'));
    }

    public function testForceReplacesTheKey(): void
    {
        $this->project->write('.env', "APP_ENV=local\nAPP_KEY=base64:old\n");

        self::assertSame(0, $this->tester()->execute(['--force']));
        self::assertStringNotContainsString('base64:old', $this->project->read('.env'));
    }

    public function testForceInProductionAsksFirst(): void
    {
        $this->project->write('.env', "APP_ENV=production\nAPP_KEY=base64:old\n");
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['--force'], ['no']));
        self::assertStringContainsString('base64:old', $this->project->read('.env'));

        self::assertSame(0, $tester->execute(['--force'], ['yes']));
        self::assertStringNotContainsString('base64:old', $this->project->read('.env'));
    }

    public function testAppendsAMissingKeyAndKeepsCrlf(): void
    {
        $this->project->write('.env', "APP_NAME=FloCMS\r\nDEBUG=true");

        $this->tester()->execute();

        self::assertMatchesRegularExpression('/^APP_NAME=FloCMS\r\nDEBUG=true\r\nAPP_KEY=base64:\S+\r\n$/', $this->project->read('.env'));
    }

    public function testShowPrintsAKeyWithoutWriting(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--show']));
        self::assertMatchesRegularExpression('/^base64:\S{44}\n$/', $tester->getDisplay());
        self::assertFileDoesNotExist($this->project->path('.env'));
    }

    public function testMissingEnvFails(): void
    {
        $tester = $this->tester();

        self::assertSame(1, $tester->execute());
        self::assertStringContainsString('Copy .env.example to .env first.', $tester->getErrorOutput());
    }

    public function testKeysWithRegexCharactersAreWrittenLiterally(): void
    {
        $this->project->write('.env', "APP_KEY=\n");
        for ($i = 0; $i < 20; $i++) {
            $key = KeyGenerator::writeToEnv($this->project->path('.env'), true);
            self::assertSame("APP_KEY={$key}\n", $this->project->read('.env'));
        }
    }

    public function testRunsThroughTheLauncher(): void
    {
        $this->project->write('.env', "APP_KEY=\n");

        $result = $this->project->flo(['key:generate']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertTrue(KeyGenerator::hasKey($this->project->path('.env')));
    }
}
