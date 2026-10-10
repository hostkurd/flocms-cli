<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Testing;

use FloCMS\CLI\Console\Output\Output;
use PHPUnit\Framework\Assert;

/**
 * Shared by CommandTester and ApplicationTester.
 */
trait CapturesOutput
{
    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    /** @var resource|null */
    private $input = null;

    private ?int $statusCode = null;

    /**
     * @param list<string> $inputs
     * @param array{verbosity?: int, decorated?: bool} $options
     */
    private function prepare(array $inputs, array $options): Output
    {
        $this->stdout = fopen('php://memory', 'w+');
        $this->stderr = fopen('php://memory', 'w+');
        $this->input = fopen('php://memory', 'w+');
        fwrite($this->input, $inputs === [] ? '' : implode("\n", $inputs) . "\n");
        rewind($this->input);

        $output = new Output($this->stdout, $this->stderr);
        if (isset($options['verbosity'])) {
            $output->setVerbosity($options['verbosity']);
        }

        return $output;
    }

    /** Everything written to STDOUT. */
    public function getDisplay(bool $normalize = false): string
    {
        return self::contents($this->stdout, $normalize);
    }

    /** Everything written to STDERR. */
    public function getErrorOutput(bool $normalize = false): string
    {
        return self::contents($this->stderr, $normalize);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode ?? throw new \LogicException('Run the command first.');
    }

    public function assertCommandIsSuccessful(string $message = ''): void
    {
        Assert::assertSame(0, $this->getStatusCode(), $message !== '' ? $message : "Command failed:\n" . $this->getErrorOutput() . $this->getDisplay());
    }

    /** @param resource|null $stream */
    private static function contents($stream, bool $normalize): string
    {
        if ($stream === null) {
            return '';
        }
        rewind($stream);
        $text = (string) stream_get_contents($stream);

        return $normalize ? str_replace("\r\n", "\n", $text) : $text;
    }
}
