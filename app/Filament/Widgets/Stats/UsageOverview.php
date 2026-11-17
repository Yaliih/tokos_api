<?php

namespace App\Filament\Widgets\Stats;

use App\Support\UsageStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UsageOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $o = UsageStats::overview();
        $daily = UsageStats::daily(14);

        return [
            Stat::make('Actifs aujourd\'hui', $o['dau'])->chart($daily['active'])->color('primary')
                ->description($o['stickiness'].' % des actifs du mois reviennent chaque jour'),
            Stat::make('Actifs sur 7 jours', $o['wau']),
            Stat::make('Actifs sur 30 jours', $o['mau'])->description('sur '.$o['total'].' inscrits'),
            Stat::make('Reviennent', $o['returning_rate'].' %')->color($o['returning_rate'] >= 40 ? 'success' : 'warning')
                ->description($o['returning'].' personnes venues au moins 2 jours ce mois'),
            Stat::make('Nouveaux inscrits (30 j)', $o['new_30'])->chart($daily['signups'])->color('success'),
            Stat::make('Jamais revenus', $o['never_back'])->color('danger')->description('aucune visite après le jour de leur inscription'),
        ];
    }
}
