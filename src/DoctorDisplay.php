<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;

final readonly class DoctorDisplay
{
    private const int MinimumWidth = 48;

    private const int MaximumWidth = 96;

    private const int BadgeWidth = 8;

    private const int KeyWidth = 10;

    private const string Indent = '  ';

    private const string CardTop = '┌';

    private const string CardSide = '│';

    private const string CardBottom = '└';

    private const string QuoteRule = '=';

    private const string QuoteIndent = '      ';

    public function __construct(private int $width = 0) {}

    public function width(): int
    {
        return max(self::MinimumWidth, min(self::MaximumWidth, $this->width > 0 ? $this->width : (new Terminal)->getWidth()));
    }

    public function isAnimated(): bool
    {
        return defined('STDOUT') && stream_isatty(STDOUT) && (new Terminal)->getWidth() >= self::MinimumWidth;
    }

    /**
     * @param  array<string, FindingSeverity>  $walked
     * @return list<string>
     */
    public function recap(array $walked, int $total): array
    {
        $lines = [];
        $completed = 0;

        foreach ($walked as $label => $severity) {
            $completed++;
            $lines[] = $this->progressLine($completed, $total, $label, $severity);
        }

        return $lines;
    }

    public function heading(string $title): string
    {
        return sprintf('<options=bold>%s</>', OutputFormatter::escape($title));
    }

    public function rule(): string
    {
        return self::Indent.str_repeat('─', $this->width() - mb_strlen(self::Indent));
    }

    public function progressLine(int $completed, int $total, string $label, FindingSeverity $severity): string
    {
        return sprintf(
            '%s%s/%s  %s  %s',
            self::Indent,
            str_pad((string) $completed, mb_strlen((string) $total), '0', STR_PAD_LEFT),
            $total,
            $this->badge($severity),
            OutputFormatter::escape($label),
        );
    }

    public function badge(FindingSeverity $severity): string
    {
        return sprintf('<fg=%s>%s</>', $severity->color(), $this->pad($severity->badge(), self::BadgeWidth));
    }

    public function groupHeading(FindingSeverity $severity, int $count): string
    {
        return sprintf(
            '%s<options=bold>%s</> <fg=%s>(%d)</>',
            self::Indent,
            mb_strtoupper($severity->heading()),
            $severity->color(),
            $count,
        );
    }

    /**
     * @return list<string>
     */
    public function findingLines(int $position, Finding $finding): array
    {
        $lines = [sprintf(
            '%s%s <fg=%s>%d</> · <options=bold>%s</>',
            self::Indent,
            self::CardTop,
            $finding->severity->color(),
            $position,
            OutputFormatter::escape($finding->subject),
        )];

        foreach ($this->entries($finding) as $key => $value) {
            $lines = [...$lines, ...$this->entryLines($key, $value)];
        }

        $last = array_key_last($lines);
        $lines[$last] = (string) preg_replace('/'.self::CardSide.'/u', self::CardBottom, $lines[$last], 1);

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    public function entries(Finding $finding): array
    {
        return array_filter([
            'Finding' => $finding->detail,
            'Fix' => $finding->remedy,
            'Time' => $finding->effort,
            'Evidence' => $finding->evidence,
            'Ask' => $finding->humanHelp,
            'Docs' => $finding->documentationUrl,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<string>
     */
    public function entryLines(string $key, string $value): array
    {
        $indent = self::Indent.self::CardSide.' ';
        $continuation = $indent.str_repeat(' ', self::KeyWidth);
        $lines = [];

        foreach ($this->wrap($value, $this->width() - mb_strlen($continuation)) as $offset => $chunk) {
            $lines[] = $offset === 0
                ? $indent.$this->pad($key, self::KeyWidth).OutputFormatter::escape($chunk)
                : $continuation.OutputFormatter::escape($chunk);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public function wrap(string $text, int $width): array
    {
        $lines = [];
        $current = '';

        foreach (explode(' ', $text) as $word) {
            if ($current === '') {
                $current = $word;

                continue;
            }

            if (mb_strlen($current) + 1 + mb_strlen($word) > $width) {
                $lines[] = $current;
                $current = $word;

                continue;
            }

            $current .= ' '.$word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * @return list<string>
     */
    public function paragraph(string $text): array
    {
        return array_map(
            static fn (string $chunk): string => self::Indent.OutputFormatter::escape($chunk),
            $this->wrap($text, $this->width() - mb_strlen(self::Indent)),
        );
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    public function quoted(array $lines, string $caption): array
    {
        $opening = sprintf('%s%s[ %s ]', self::Indent, str_repeat(self::QuoteRule, 3), OutputFormatter::escape($caption));
        $width = $this->width();

        return [
            $opening.str_repeat(self::QuoteRule, max(3, $width - mb_strlen(strip_tags($opening)))),
            '',
            ...array_map(static fn (string $line): string => self::QuoteIndent.OutputFormatter::escape($line), $lines),
            '',
            self::Indent.str_repeat(self::QuoteRule, $width - mb_strlen(self::Indent)),
        ];
    }

    public function indented(string $text): string
    {
        return self::Indent.OutputFormatter::escape($text);
    }

    private function pad(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - mb_strlen($text)));
    }
}
