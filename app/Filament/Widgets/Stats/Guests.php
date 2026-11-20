<?php

namespace App\Filament\Widgets\Stats;

use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Visiteurs sans compte : combien de personnes, et quelle part des vues.
 *
 * Une « vue » = une personne sur un contenu (compétition ou duel). La table
 * content_views n'accepte qu'une ligne par personne et par contenu : le total
 * ne gonfle donc pas au rechargement, et le nombre de personnes est distinct
 * du nombre de vues (quelqu'un qui ouvre 3 compétitions = 3 vues, 1 personne).
 */
class Guests extends Widget
{
    protected string $view = 'filament.widgets.guests';

    protected int|string|array $columnSpan = 'full';

    /**
     * Rendu avec la page, pas en différé.
     *
     * Les widgets Filament sont paresseux par défaut : la page s'affiche d'abord
     * avec un squelette, puis un second appel charge les chiffres. Cet appel ne
     * transporte pas « ?periode=... », le bloc retombait donc toujours sur 7 jours
     * et les boutons de période semblaient sans effet. Rendu direct = une seule
     * requête agrégée et des chiffres qui suivent vraiment la période choisie.
     */
    protected static bool $isLazy = false;

    /** Périodes proposées : clé gardée dans l'URL, libellé affiché. */
    public const PERIODES = ['24h' => '24 h', '7j' => '7 jours', '30j' => '30 jours', 'tout' => 'Tout'];

    /** Choix courant, aussi dans l'URL (?periode=24h) pour garder la même vue au rechargement. */
    #[Url(as: 'periode')]
    public string $periode = '7j';

    public function mount(): void
    {
        // Une valeur inconnue dans l'URL (?periode=xyz) retombe sur la période par défaut
        $this->periode = $this->periodeValide($this->periode);
    }

    /** Boutons du bloc : le calcul suit tout de suite, sans recharger la page. */
    public function choisirPeriode(string $periode): void
    {
        $this->periode = $this->periodeValide($periode);
    }

    private function periodeValide(string $periode): string
    {
        return array_key_exists($periode, self::PERIODES) ? $periode : '7j';
    }

    private function debut(): ?Carbon
    {
        return match ($this->periode) {
            '24h' => now()->subDay(),
            '7j' => now()->subDays(7),
            '30j' => now()->subDays(30),
            default => null, // « Tout »
        };
    }

    protected function getViewData(): array
    {
        $debut = $this->debut();

        // Un seul passage en base : vues et personnes, visiteurs et membres
        $chiffres = DB::table('content_views')
            ->when($debut, fn ($q) => $q->where('created_at', '>=', $debut))
            ->selectRaw('COUNT(*) AS vues')
            ->selectRaw('SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) AS vues_visiteurs')
            ->selectRaw('COUNT(DISTINCT CASE WHEN user_id IS NULL THEN viewer_key END) AS personnes_visiteurs')
            ->selectRaw('COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN viewer_key END) AS personnes_membres')
            ->selectRaw('MIN(created_at) AS premiere_vue')
            ->first();

        $vues = (int) ($chiffres->vues ?? 0);
        $vuesVisiteurs = (int) ($chiffres->vues_visiteurs ?? 0);
        $vuesMembres = max(0, $vues - $vuesVisiteurs);
        $personnesVisiteurs = (int) ($chiffres->personnes_visiteurs ?? 0);
        $personnesMembres = (int) ($chiffres->personnes_membres ?? 0);

        // Part des vues : les vues sans compte rapportées à toutes les vues
        $part = $vues > 0 ? (int) round($vuesVisiteurs * 100 / $vues) : 0;

        return [
            'periode' => $this->periode,
            'periodes' => self::PERIODES,
            'vues' => $vues,
            'vuesVisiteurs' => $vuesVisiteurs,
            'vuesMembres' => $vuesMembres,
            'personnesVisiteurs' => $personnesVisiteurs,
            'personnesMembres' => $personnesMembres,
            'part' => $part,
            'plage' => $this->plage($debut, $chiffres->premiere_vue ?? null, $vues),
        ];
    }

    /** « du 13 au 20 nov. 2026 », « depuis le 23 oct. 2026 »… pour que les chiffres soient datés. */
    private function plage(?Carbon $debut, ?string $premiereVue, int $vues): string
    {
        if ($this->periode === '24h') {
            return 'sur les dernières 24 h';
        }

        if ($this->periode === 'tout') {
            return $vues > 0 && $premiereVue
                ? 'depuis le '.Carbon::parse($premiereVue)->locale('fr')->translatedFormat('j M Y')
                : 'toutes périodes';
        }

        $fin = now();

        return 'du '.$debut?->locale('fr')->translatedFormat('j M')
            .' au '.$fin->locale('fr')->translatedFormat('j M Y');
    }
}
