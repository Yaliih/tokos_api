<?php

namespace App\Support;

use App\Models\Competition;
use App\Models\Rating;
use App\Models\Space;
use App\Models\User;

/** Ce que montre le petit site d'un Espace : en cours, palmarès, talents, responsables, chiffres. */
class SpaceSiteData
{
    public static function for(Space $space, ?User $viewer): array
    {
        $base = Competition::visible()->where('organizer_type', Space::class)->where('organizer_id', $space->id);
        $live = (clone $base)->whereIn('status', [Competition::STATUS_OPEN, Competition::STATUS_LIVE])
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END")->latest()->limit(6)->get()
            ->map(fn ($c) => Presenter::competition($c, $viewer));
        $past = (clone $base)->where('status', Competition::STATUS_COMPLETED)->whereNotNull('winner_entry_id')
            ->latest('completed_at')->limit(6)->get()->map(fn ($c) => Presenter::competition($c, $viewer));
        $talents = collect(Rankings::people(Rating::currentSeason(), null, $space->id, 8));
        $template = array_key_exists((string) $space->site_template, config('sites.templates')) ? $space->site_template : config('sites.default');

        return [
            'space' => $space,
            'p' => Participant::present($space),
            'label' => Rankings::spaceLabel($space),
            'template' => $template,
            'live' => $live,
            'past' => $past,
            'talents' => $talents,
            'managers' => $space->managers()->limit(4)->get()->map(fn ($u) => Participant::present($u)),
            'counts' => [
                'members' => (int) $space->members_count,
                'competitions' => (clone $base)->count(),
                'votes' => (int) (clone $base)->sum('votes_count'),
            ],
            'mainUrl' => rtrim(config('app.url'), '/'),
            'isMember' => $viewer ? $space->hasAffiliate($viewer) : false,
        ];
    }
}
