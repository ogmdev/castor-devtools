<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use Symfony\Component\Console\Formatter\OutputFormatter;

final readonly class DoctorScene
{
    private const int ElephantWidth = 9;

    private const int LineCount = 7;

    private const int MinimumRoadWidth = 20;

    private const int MaximumRoadWidth = 40;

    private const string Footprint = 'o';

    private const array WalkingPoses = [
        ['  ____   ', ' /  o \__', '(      _)', ' ||  ||  '],
        ['  ____   ', ' /  o \__', '(      _)', ' /|  /|  '],
    ];

    private const array TrumpetingPose = ['  ____ _)', ' /  o \/ ', '(       )', ' ||  ||  '];

    public function __construct(private DoctorDisplay $display = new DoctorDisplay) {}

    public function height(): int
    {
        return self::LineCount;
    }

    public function roadWidth(): int
    {
        return max(self::MinimumRoadWidth, min(self::MaximumRoadWidth, $this->display->width() - 4));
    }

    /**
     * @param  list<FindingSeverity>  $results
     */
    public function frame(array $results, int $total, string $label): string
    {
        $total = max(1, $total);
        $results = array_slice($results, 0, $total);
        $completed = count($results);
        $road = $this->roadWidth();

        $pose = $this->pose($completed, $total);
        $column = $this->elephantColumn($completed, $total);
        $ground = array_fill(0, $road, ' ');

        foreach ($this->footprintPositions($completed, $total) as $offset => $position) {
            $ground[$position] = sprintf('<fg=%s>%s</>', $results[$offset]->color(), self::Footprint);
        }

        foreach (mb_str_split($pose[3]) as $offset => $character) {
            if ($character !== ' ') {
                $ground[$column + $offset] = $character;
            }
        }

        $indent = '  '.str_repeat(' ', $column);

        return implode(PHP_EOL, [
            '  '.OutputFormatter::escape($this->truncate($label, $road)),
            rtrim($indent.OutputFormatter::escape($pose[0])),
            rtrim($indent.OutputFormatter::escape($pose[1])),
            rtrim($indent.OutputFormatter::escape($pose[2])),
            '  '.implode('', $ground),
            '  '.str_repeat('=', $road),
            sprintf('  %s  %d/%d %s', $this->progressBar($results, $total), $completed, $total, $this->caption($completed, $total)),
        ]);
    }

    /**
     * @return list<string>
     */
    public function pose(int $completed, int $total): array
    {
        if ($completed >= $total) {
            return self::TrumpetingPose;
        }

        return self::WalkingPoses[$completed % count(self::WalkingPoses)];
    }

    public function caption(int $completed, int $total): string
    {
        return $completed >= $total ? 'every check done!' : 'checking';
    }

    /**
     * @param  list<FindingSeverity>  $results
     */
    public function progressBar(array $results, int $total): string
    {
        $done = '';

        foreach ($results as $result) {
            $done .= sprintf('<fg=%s>#</>', $result->color());
        }

        return sprintf('[%s%s]', $done, str_repeat('.', max(0, $total - count($results))));
    }

    /**
     * @return list<int>
     */
    public function footprintPositions(int $completed, int $total): array
    {
        $positions = [];

        for ($step = 0; $step < $completed; $step++) {
            $positions[] = $step * $this->spacing($total);
        }

        return $positions;
    }

    public function elephantColumn(int $completed, int $total): int
    {
        return min($completed * $this->spacing($total), $this->roadWidth() - self::ElephantWidth);
    }

    private function spacing(int $total): int
    {
        return max(1, intdiv($this->roadWidth() - self::ElephantWidth, max(1, $total)));
    }

    private function truncate(string $label, int $width): string
    {
        return mb_strlen($label) <= $width ? $label : mb_substr($label, 0, $width - 1).'…';
    }
}
