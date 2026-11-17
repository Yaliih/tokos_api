<?php

namespace App\Filament\Widgets\Stats;

use App\Support\UsageStats;
use Filament\Widgets\ChartWidget;

class RecencyChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Dernière visite';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $r = UsageStats::recency();

        return [
            'labels' => array_keys($r),
            'datasets' => [['data' => array_values($r), 'backgroundColor' => ['#6C5CE7', '#A29BFE', '#F59E0B', '#F43F5E', '#CBD5E1']]],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['display' => false], 'y' => ['display' => false]], 'plugins' => ['legend' => ['position' => 'right']]];
    }
}
