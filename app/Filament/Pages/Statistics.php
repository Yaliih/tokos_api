<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Stats\ActionsChart;
use App\Filament\Widgets\Stats\ActiveChart;
use App\Filament\Widgets\Stats\RecencyChart;
use App\Filament\Widgets\Stats\RetentionTable;
use App\Filament\Widgets\Stats\UsageOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Statistiques d'usage : qui vient, qui revient, ce qu'on fait. */
class Statistics extends Page
{
    protected string $view = 'filament.pages.statistics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Statistiques';

    protected static ?string $title = 'Statistiques';

    protected static ?string $slug = 'statistiques';

    protected static ?int $navigationSort = 2;

    protected function getHeaderWidgets(): array
    {
        return [UsageOverview::class, ActiveChart::class, ActionsChart::class, RecencyChart::class, RetentionTable::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }
}
