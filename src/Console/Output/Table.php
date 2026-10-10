<?php
declare(strict_types=1);

namespace FloCMS\CLI\Console\Output;

/**
 * A plain ASCII table (safe in every Windows console). Cell values are
 * escaped; wrap a value in Styled to keep its style tags.
 *
 *
 *     +----+-------+
 *     | id | name  |
 *     +----+-------+
 *     | 1  | Admin |
 *     +----+-------+
 */
final class Table
{
    /**
     * @param list<string> $headers
     * @param list<array<int|string, mixed>> $rows
     * @return list<string> lines
     */
    public static function render(array $headers, array $rows): array
    {
        $cells = [];
        foreach ($rows as $row) {
            $values = array_is_list($row) ? $row : array_map(static fn ($h) => $row[$h] ?? null, $headers);
            $cells[] = array_map([self::class, 'cell'], array_values($values));
        }

        $columns = max(count($headers), ...array_map('count', $cells ?: [[]]));
        $widths = array_fill(0, $columns, 0);
        foreach ([$headers, ...$cells] as $line) {
            foreach ($line as $index => $cell) {
                $widths[$index] = max($widths[$index], Formatter::width((string) $cell));
            }
        }

        $border = '+' . implode('+', array_map(static fn (int $w): string => str_repeat('-', $w + 2), $widths)) . '+';
        $lines = [$border];
        if ($headers !== []) {
            $lines[] = self::line(array_map(static fn ($h): string => '<info>' . $h . '</info>', $headers), $widths);
            $lines[] = $border;
        }
        foreach ($cells as $line) {
            $lines[] = self::line($line, $widths);
        }
        if ($cells !== []) {
            $lines[] = $border;
        }

        return $lines;
    }

    /**
     * @param list<string> $cells
     * @param list<int> $widths
     */
    private static function line(array $cells, array $widths): string
    {
        $out = [];
        foreach ($widths as $index => $width) {
            $cell = $cells[$index] ?? '';
            $out[] = ' ' . $cell . str_repeat(' ', $width - Formatter::width($cell)) . ' ';
        }

        return '|' . implode('|', $out) . '|';
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value instanceof Styled => $value->text,
            $value === null => '-',
            $value === true => 'yes',
            $value === false => 'no',
            is_array($value) => Formatter::escape(implode(',', array_map('strval', $value))),
            default => Formatter::escape(str_replace(["\r", "\n"], ' ', (string) $value)),
        };
    }
}
