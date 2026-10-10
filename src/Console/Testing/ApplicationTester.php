<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Testing;

use FloCMS\CLI\Console\Application;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Kernel;

/**
 * Runs command lines against an Application and captures STDOUT, STDERR and
 * the exit code. Use it in your application's or package's PHPUnit tests:
 *
 *     $tester = ApplicationTester::forProject(dirname(__DIR__));
 *     $tester->run(['make:controller', 'Blog', '--dry-run']);
 *     self::assertSame(0, $tester->getStatusCode());
 *     self::assertStringContainsString('BlogController.php', $tester->getDisplay());
 */
final class ApplicationTester
{
    use CapturesOutput;

    public function __construct(private readonly Application $application)
    {
    }

    /** Boot the kernel for a project root (built-in, package and app commands). */
    public static function forProject(string $root): self
    {
        return new self(Kernel::boot($root));
    }

    public function application(): Application
    {
        return $this->application;
    }

    /**
     * @param list<string> $arguments the command line without the script name
     * @param list<string> $inputs answers to prompts, one per line
     * @param array{interactive?: bool, verbosity?: int, decorated?: bool} $options
     */
    public function run(array $arguments, array $inputs = [], array $options = []): int
    {
        $output = $this->prepare($inputs, $options);
        $this->application->setOutput($output);
        $this->application->setInputStream($this->input);
        $this->application->setInteractive($options['interactive'] ?? true);

        return $this->statusCode = $this->application->run($arguments);
    }
}
