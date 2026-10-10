<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Unit;

use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\ArgvParser;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\InputException;
use FloCMS\CLI\Console\Input\Option;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgvParserTest extends TestCase
{
    private static function definition(): Definition
    {
        return (new Definition())
            ->argument('name', Argument::REQUIRED, 'Name')
            ->argument('view', Argument::OPTIONAL, 'View', 'index')
            ->option('force', 'f', Option::VALUE_NONE)
            ->option('dry-run', 'd', Option::VALUE_NONE)
            ->option('scopes', null, Option::VALUE_REQUIRED, '', '')
            ->option('port', 'p', Option::VALUE_REQUIRED, '', '8000')
            ->option('table', null, Option::VALUE_OPTIONAL)
            ->option('allow', 'a', Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY)
            ->merge(Application::globalDefinition());
    }

    public function testArgumentsAndDefaults(): void
    {
        $input = ArgvParser::parse(['Blog'], self::definition());

        self::assertSame('Blog', $input->argument('name'));
        self::assertSame('index', $input->argument('view'));
        self::assertFalse($input->option('force'));
        self::assertSame('8000', $input->option('port'));
        self::assertSame([], $input->option('allow'));
        self::assertNull($input->option('table'));
        self::assertSame(0, (int) $input->option('verbose'));
    }

    public function testLongOptionsWithAndWithoutEquals(): void
    {
        $input = ArgvParser::parse(['Blog', '--scopes=a,b', '--port', '9000', 'show', '--table'], self::definition());

        self::assertSame('a,b', $input->option('scopes'));
        self::assertSame('9000', $input->option('port'));
        self::assertSame('show', $input->argument('view'));
        self::assertTrue($input->option('table'));
        self::assertSame('posts', ArgvParser::parse(['x', '--table=posts'], self::definition())->option('table'));
    }

    public function testShortOptions(): void
    {
        $input = ArgvParser::parse(['-fd', 'Blog', '-p8080', '-a', '1.2.3.4', '-a10.0.0.0/8'], self::definition());

        self::assertTrue($input->option('force'));
        self::assertTrue($input->option('dry-run'));
        self::assertSame('8080', $input->option('port'));
        self::assertSame(['1.2.3.4', '10.0.0.0/8'], $input->option('allow'));
    }

    #[DataProvider('verbosity')]
    public function testVerbosityLevels(array $tokens, int $level): void
    {
        self::assertSame($level, ArgvParser::parse(['x', ...$tokens], self::definition())->option('verbose'));
    }

    public static function verbosity(): array
    {
        return [
            [['-v'], 1],
            [['-vv'], 2],
            [['-vvv'], 3],
            [['-v', '-v'], 2],
            [['--verbose'], 1],
            [['--verbose=3'], 3],
        ];
    }

    public function testDoubleDashEndsOptionsAndNegativeNumbersAreArguments(): void
    {
        $input = ArgvParser::parse(['--', '--weird', '-5'], self::definition());
        self::assertSame('--weird', $input->argument('name'));
        self::assertSame('-5', $input->argument('view'));

        self::assertSame('-1', ArgvParser::parse(['-1'], self::definition())->argument('name'));
    }

    public function testArrayArgumentTakesTheRest(): void
    {
        $definition = (new Definition())->argument('files', Argument::IS_ARRAY | Argument::REQUIRED);

        self::assertSame(['a', 'b', 'c'], ArgvParser::parse(['a', 'b', 'c'], $definition)->argument('files'));
        $this->expectException(InputException::class);
        ArgvParser::parse([], $definition);
    }

    #[DataProvider('invalidInput')]
    public function testInvalidInputIsRejectedWithAClearMessage(array $tokens, string $message): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage($message);

        ArgvParser::parse($tokens, self::definition());
    }

    public static function invalidInput(): array
    {
        return [
            'missing argument' => [[], 'Not enough arguments (missing: "name").'],
            'too many' => [['a', 'b', 'c'], 'Too many arguments, expected: <name> [<view>].'],
            'unknown long' => [['a', '--nope'], 'The "--nope" option does not exist.'],
            'typo hint' => [['a', '--forse'], 'Did you mean "--force"?'],
            'unknown short' => [['a', '-z'], 'The "-z" option does not exist.'],
            'flag with value' => [['a', '--force=yes'], 'The "--force" option does not accept a value.'],
            'missing value' => [['a', '--scopes'], 'The "--scopes" option requires a value.'],
            'missing short value' => [['a', '-p'], 'The "-p" option requires a value.'],
            'option as value' => [['a', '--port', '--force'], 'The "--port" option requires a value.'],
        ];
    }

    public function testDefinitionRejectsInvalidDeclarations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Definition())->argument('a', Argument::OPTIONAL)->argument('b', Argument::REQUIRED);
    }

    public function testDuplicateShortcutsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Definition())->option('force', 'f')->option('fast', 'f');
    }

    public function testCommandShortcutsWinOverGlobalOnes(): void
    {
        $definition = (new Definition())->option('host', 'h', Option::VALUE_REQUIRED)->merge(Application::globalDefinition());

        self::assertSame('0.0.0.0', ArgvParser::parse(['-h', '0.0.0.0'], $definition)->option('host'));
        self::assertTrue(ArgvParser::parse(['--help'], $definition)->option('help'));
    }

    public function testSynopsis(): void
    {
        self::assertSame('[options] [--] <name> [<view>]', self::definition()->synopsis());
        self::assertSame('-f, --force', self::definition()->getOption('force')?->synopsis());
        self::assertSame('    --scopes=SCOPES', self::definition()->getOption('scopes')?->synopsis());
        self::assertSame('    --table[=TABLE]', self::definition()->getOption('table')?->synopsis());
    }
}
