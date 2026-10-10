<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Console\Output\ProgressBar;
use FloCMS\CLI\Console\Output\Spinner;
use FloCMS\CLI\Console\Output\Table;
use PHPUnit\Framework\TestCase;

final class OutputToolkitTest extends TestCase
{
    public function testTagsBecomeAnsiCodesWhenDecorated(): void
    {
        $formatter = new Formatter(true);

        self::assertSame("\033[32mok\033[0m", $formatter->format('<info>ok</info>'));
        self::assertSame("\033[37;41mbad\033[0m", $formatter->format('<error>bad</error>'));
        self::assertSame("\033[31;1mx\033[0m", $formatter->format('<fg=red;options=bold>x</>'));
        // Nested styles restore the outer one
        self::assertSame("\033[32ma\033[1mb\033[0m\033[32mc\033[0m", $formatter->format('<info>a<b>b</b>c</info>'));
    }

    public function testTagsAreStrippedWithoutColors(): void
    {
        $formatter = new Formatter(false);

        self::assertSame('ok and <name>', $formatter->format('<info>ok</info> and <name>'));
        self::assertSame('literal <info>', $formatter->format('literal \<info>'));
        self::assertSame('<info>', $formatter->format(Formatter::escape('<info>')));
        self::assertSame(5, Formatter::width('<info>héllo</info>'));
        self::assertSame('plain', Formatter::strip("\033[32mplain\033[0m"));
    }

    public function testNoColorDisablesDecoration(): void
    {
        putenv('NO_COLOR=1');
        try {
            self::assertFalse(Output::supportsColor(STDOUT));
        } finally {
            putenv('NO_COLOR');
        }
        // Memory streams are never terminals
        self::assertFalse(Output::supportsColor(fopen('php://memory', 'w+')));
    }

    public function testOutputCanBeForcedOnAndOff(): void
    {
        $out = fopen('php://memory', 'w+');
        $output = new Output($out, fopen('php://memory', 'w+'), true);
        $output->write('<info>x</info>');
        $output->setDecorated(false);
        $output->write('<info>y</info>');

        rewind($out);
        self::assertSame("\033[32mx\033[0my", stream_get_contents($out));
    }

    public function testTable(): void
    {
        $lines = Table::render(['id', 'name', 'scopes'], [
            [1, 'Admin', ['a', 'b']],
            ['id' => 22, 'name' => null, 'scopes' => 'x<y'],
        ]);

        self::assertSame([
            '+----+-------+--------+',
            '| <info>id</info> | <info>name</info>  | <info>scopes</info> |',
            '+----+-------+--------+',
            '| 1  | Admin | a,b    |',
            '| 22 | -     | x\<y    |',
            '+----+-------+--------+',
        ], $lines);
    }

    public function testProgressBarWithoutTerminalPrintsQuarters(): void
    {
        [$output, $err] = self::makeOutput();
        $bar = (new ProgressBar($output, 8))->start();
        for ($i = 0; $i < 8; $i++) {
            $bar->advance();
        }
        $bar->finish('done');

        rewind($err);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($err))));
        self::assertCount(4, $lines);
        self::assertStringStartsWith('2/8 [=======>', $lines[0]);
        self::assertSame('8/8 [============================] 100% done', $lines[3]);
    }

    public function testProgressBarRedrawsInATerminal(): void
    {
        [$output, $err] = self::makeOutput(true);
        $bar = (new ProgressBar($output, 2))->start();
        $bar->advance();
        $bar->finish();

        rewind($err);
        $text = (string) stream_get_contents($err);
        self::assertStringContainsString("\r\033[2K1/2", $text);
        self::assertStringEndsWith("\n", $text);
    }

    public function testQuietSilencesProgressAndSpinner(): void
    {
        [$output, $err] = self::makeOutput();
        $output->setVerbosity(Output::QUIET);
        (new ProgressBar($output, 2))->start()->finish();
        self::assertSame(42, (new Spinner($output, 'Working'))->run(static fn () => 42));

        rewind($err);
        self::assertSame('', stream_get_contents($err));
    }

    public function testSpinnerPrintsOnceWithoutTerminal(): void
    {
        [$output, $err] = self::makeOutput();
        (new Spinner($output, 'Connecting'))->run(static function (Spinner $s): void {
            $s->tick();
            $s->tick();
        });

        rewind($err);
        self::assertSame('Connecting...' . "\n", stream_get_contents($err));
    }

    /** @return array{0: Output, 1: resource} */
    private static function makeOutput(bool $decorated = false): array
    {
        $err = fopen('php://memory', 'w+');

        return [new Output(fopen('php://memory', 'w+'), $err, $decorated), $err];
    }
}
