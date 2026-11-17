<?php

namespace App\Filament\Widgets\Stats;

use App\Support\UsageStats;
use Filament\Widgets\Widget;

class RetentionTable extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.retention-table';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return ['rows' => UsageStats::retention(8)];
    }
}
