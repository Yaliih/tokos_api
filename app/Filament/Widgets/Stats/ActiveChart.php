<?php

namespace App\Filament\Widgets\Stats;

use App\Support\UsageStats;
use Filament\Widgets\ChartWidget;

class ActiveChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Actifs et inscriptions par jour (30 jours)';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $d = UsageStats::daily(30);

        return [
            'labels' => $d['labels'],
            'datasets' => [
                ['label' => 'Actifs', 'data' => $d['active'], 'borderColor' => '#6C5CE7', 'backgroundColor' => 'rgba(108,92,231,.12)', 'fill' => true, 'tension' => .3],
                ['label' => 'Inscriptions', 'data' => $d['signups'], 'borderColor' => '#10B981', 'backgroundColor' => 'transparent', 'tension' => .3],
            ],
        ];
    }
}
