<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Output;

/**
 * Text with style tags that a Table should render instead of escaping:
 *
 *     $this->table(['Status'], [[new Styled('<info>Ran</info>')]]);
 */
final class Styled implements \Stringable
{
    public function __construct(public readonly string $text)
    {
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
