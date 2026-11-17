<?php

namespace App\Filament\Widgets;

use App\Models\Competition;
use App\Models\CompetitionVote;
use App\Models\ContentReport;
use App\Models\User;
use App\Models\VerificationRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Les chiffres du jour sur le tableau de bord de l'admin. */
class TokosStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $week = User::where('created_at', '>=', now()->subDays(7))->count();
        $signups = collect(range(13, 0))->map(fn ($days) => User::whereDate('created_at', now()->subDays($days)->toDateString())->count())->all();
        $votes = collect(range(13, 0))->map(fn ($days) => CompetitionVote::whereDate('created_at', now()->subDays($days)->toDateString())->count())->all();

        return [
            Stat::make('Utilisateurs', number_format(User::count(), 0, ',', ' '))->description('+'.$week.' cette semaine')->chart($signups)->color('primary'),
            Stat::make('Compétitions en cours', Competition::whereIn('status', ['open', 'live'])->whereNull('hidden_at')->count())
                ->description(Competition::where('created_at', '>=', now()->subDays(7))->count().' créées cette semaine'),
            Stat::make('Votes (24 h)', number_format(CompetitionVote::where('created_at', '>=', now()->subDay())->count(), 0, ',', ' '))->chart($votes)->color('success'),
            Stat::make('Signalements à traiter', $reports = ContentReport::where('status', 'pending')->count())->color($reports ? 'danger' : 'gray')
                ->url(route('filament.admin.resources.reports.index')),
            Stat::make('Certifications à examiner', $certs = VerificationRequest::where('status', 'pending')->count())->color($certs ? 'warning' : 'gray')
                ->url(route('filament.admin.resources.certifications.index')),
        ];
    }
}
