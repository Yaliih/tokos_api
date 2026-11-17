<?php

namespace App\Filament\Widgets\Stats;

use App\Support\UsageStats;
use Filament\Widgets\ChartWidget;

class ActionsChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Ce que les gens font (14 jours)';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $d = UsageStats::actions(14);
        $colors = ['#6C5CE7', '#F59E0B', '#10B981', '#F43F5E'];
        $sets = [];
        foreach (array_values(array_keys($d['series'])) as $i => $name) {
            $sets[] = ['label' => $name, 'data' => $d['series'][$name], 'backgroundColor' => $colors[$i], 'borderRadius' => 4];
        }

        return ['labels' => $d['labels'], 'datasets' => $sets];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true]]];
    }
}
