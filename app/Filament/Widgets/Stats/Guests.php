<?php

namespace App\Filament\Widgets\Stats;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Vues des visiteurs sans compte : combien, quelle part du total, sur une période choisie.
 * Une « vue » = une personne (cookie pour le site, IP+appareil pour l'API), pas un rechargement.
 */
class Guests extends Widget
{
    protected string $view = 'filament.widgets.guests';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return true;
    }

    protected function getViewData(): array
    {
        $periode = in_array(request('periode'), ['24h', '7j', '30j', 'tout'], true) ? request('periode') : '7j';
        $from = match ($periode) {
            '24h' => now()->subDay(),
            '7j' => now()->subDays(7),
            '30j' => now()->subDays(30),
            default => null,
        };

        $q = fn () => DB::table('content_views')->when($from, fn ($w) => $w->where('created_at', '>=', $from));
        $total = $q()->count();
        $guests = $q()->whereNull('user_id')->count();
        $members = max(0, $total - $guests);
        $part = $total > 0 ? round($guests * 100 / $total) : 0;

        // Distincts : combien de personnes différentes derrière ces vues
        $guestPeople = $q()->whereNull('user_id')->distinct()->count('viewer_key');

        return [
            'periode' => $periode,
            'total' => $total,
            'guests' => $guests,
            'members' => $members,
            'part' => $part,
            'guestPeople' => $guestPeople,
        ];
    }
}