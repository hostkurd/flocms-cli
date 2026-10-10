<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console;

use FloCMS\CLI\Console\Input\Input;
use FloCMS\CLI\Console\Output\Formatter;
use FloCMS\CLI\Console\Output\Output;

/**
 * Interactive questions. Questions go to STDERR so piped STDOUT stays clean.
 * With --no-interaction (or when input ends) the default answer is used;
 * a question without a default then fails with a clear message.
 */
final class Prompt
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Input $input, private readonly Output $output)
    {
    }

    /**
     * @param null|callable(string): (string|null) $validate return an error message, or null when valid
     */
    public function ask(string $question, ?string $default = null, ?callable $validate = null): string
    {
        $label = '<info>' . $question . '</info>' . ($default !== null && $default !== '' ? ' [<comment>' . Formatter::escape($default) . '</comment>]' : '');

        return $this->loop($label, $default, $validate, false);
    }

    public function secret(string $question, ?callable $validate = null): string
    {
        return $this->loop('<info>' . $question . '</info>', null, $validate, true);
    }

    public function confirm(string $question, bool $default = false): bool
    {
        if (!$this->input->isInteractive()) {
            return $default;
        }
        $label = '<info>' . $question . '</info> (' . ($default ? 'Y/n' : 'y/N') . ')';
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $answer = $this->readAnswer($label, false);
            if ($answer === null) {
                return $default;
            }
            $answer = strtolower(trim($answer));
            if ($answer === '') {
                return $default;
            }
            if (in_array($answer, ['y', 'yes'], true)) {
                return true;
            }
            if (in_array($answer, ['n', 'no'], true)) {
                return false;
            }
            $this->output->error('<comment>Please answer yes or no.</comment>');
        }

        return $default;
    }

    /**
     * The answer can be the key or the label (case-insensitive).
     *
     * @param array<int|string, string> $choices key => label
     * @param int|string|null $default a key
     * @return int|string the chosen key
     */
    public function choice(string $question, array $choices, int|string|null $default = null): int|string
    {
        $keys = array_keys($choices);
        if (!$this->input->isInteractive()) {
            return $this->defaultChoice($choices, $default, $question);
        }

        $this->output->error('<info>' . $question . '</info>' . ($default !== null ? ' [<comment>' . $default . '</comment>]' : ''));
        foreach ($choices as $key => $label) {
            $this->output->error('  [<comment>' . $key . '</comment>] ' . $label);
        }
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $answer = $this->readAnswer('>', false);
            if ($answer === null || trim($answer) === '') {
                return $this->defaultChoice($choices, $default, $question);
            }
            $answer = trim($answer);
            foreach ($keys as $key) {
                if ((string) $key === $answer) {
                    return $key;
                }
            }
            foreach ($choices as $key => $label) {
                if (strcasecmp($label, $answer) === 0) {
                    return $key;
                }
            }
            $this->output->error('<comment>"' . Formatter::escape($answer) . '" is not one of the choices.</comment>');
        }

        throw new CommandFailedException('No valid answer to "' . $question . '".');
    }

    /** @param array<int|string, string> $choices */
    private function defaultChoice(array $choices, int|string|null $default, string $question): int|string
    {
        if ($default === null) {
            throw new CommandFailedException('"' . $question . '" needs an answer; run without --no-interaction or pass it as an option.');
        }
        if (!array_key_exists($default, $choices)) {
            throw new CommandFailedException('Invalid default "' . $default . '" for "' . $question . '".');
        }

        return $default;
    }

    private function loop(string $label, ?string $default, ?callable $validate, bool $hidden): string
    {
        if (!$this->input->isInteractive()) {
            return $this->validated($default, $validate, $label);
        }
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $answer = $this->readAnswer($label, $hidden);
            if ($answer === null) {
                return $this->validated($default, $validate, $label);
            }
            $answer = $answer === '' && $default !== null ? $default : $answer;
            $error = $validate !== null ? $validate($answer) : null;
            if ($error === null) {
                return $answer;
            }
            $this->output->error('<comment>' . $error . '</comment>');
        }

        throw new CommandFailedException('No valid answer to "' . Formatter::strip($label) . '".');
    }

    private function validated(?string $default, ?callable $validate, string $label): string
    {
        if ($default === null) {
            throw new CommandFailedException('"' . Formatter::strip($label) . '" needs an answer; run without --no-interaction or pass it as an option.');
        }
        $error = $validate !== null ? $validate($default) : null;
        if ($error !== null) {
            throw new CommandFailedException($error);
        }

        return $default;
    }

    /** @return string|null null at end of input */
    private function readAnswer(string $label, bool $hidden): ?string
    {
        $this->output->error($label . ' ', false);
        $stream = $this->input->stream();

        if ($hidden && Output::isTerminal($stream)) {
            $answer = self::readHidden($stream);
            $this->output->error('');
            if ($answer !== null) {
                return $answer;
            }
            $this->output->error('<comment>Hidden input is not available; what you type will be visible.</comment>');
            $this->output->error($label . ' ', false);
        }

        $line = fgets($stream);
        if ($line === false) {
            $this->output->error('');

            return null;
        }

        return rtrim($line, "\r\n");
    }

    /** @param resource $stream */
    private static function readHidden($stream): ?string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $value = function_exists('shell_exec') ? @shell_exec(self::windowsHiddenCommand()) : null;

            return is_string($value) ? rtrim($value, "\r\n") : null;
        }
        if (!function_exists('shell_exec')) {
            return null;
        }
        $mode = @shell_exec('stty -g 2>/dev/null');
        if (!is_string($mode) || trim($mode) === '') {
            return null;
        }
        @shell_exec('stty -echo');
        $line = fgets($stream);
        @shell_exec('stty ' . escapeshellarg(trim($mode)));

        return $line === false ? null : rtrim($line, "\r\n");
    }

    /**
     * Windows: PowerShell reads from the console without echo. It must not run
     * with -NonInteractive, which makes Read-Host fail. Its errors are dropped,
     * so a failure falls back to a visible prompt instead of printing a stack.
     */
    public static function windowsHiddenCommand(): string
    {
        return 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))" 2>NUL';
    }
}
