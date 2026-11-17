<?php

namespace App\Support;

use App\Models\CompetitionEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Groupes de personnes pour les annonces de l'admin, recalculés à chaque envoi
 * (un rappel mensuel « inactifs depuis 30 jours » touche donc les bonnes personnes chaque mois).
 * Activité = jours de visite (table user_visits, site et app).
 */
class Audiences
{
    /** clé => [libellé, ce que veut dire « nombre », valeur par défaut] */
    public const SEGMENTS = [
        'new' => ['Nouveaux inscrits', 'inscrits depuis (jours)', 1],
        'most_active' => ['Les plus actifs', 'combien de personnes (les plus de jours de visite sur 30 jours)', 50],
        'least_active' => ['Les moins actifs', 'combien de personnes (les moins de jours de visite sur 30 jours)', 50],
        'one_visit' => ['Venus une seule fois', 'pas revenus depuis (jours)', 3],
        'inactive' => ['Plus revenus', 'absents depuis (jours)', 30],
        'never_played' => ['Jamais participé à une compétition', 'inscrits depuis au moins (jours)', 2],
        'city' => ['Une ville', 'non utilisé', 0],
    ];

    public static function options(): array
    {
        return collect(self::SEGMENTS)->map(fn ($s) => $s[0])->all();
    }

    private static function visitDays(int $days)
    {
        return DB::table('user_visits')->select('user_id', DB::raw('count(*) as n'))
            ->where('day', '>=', now()->subDays($days)->toDateString())->groupBy('user_id');
    }

    /** La requête des personnes d'un groupe. */
    public static function query(string $key, int $n = 0, ?string $city = null): Builder
    {
        $n = $n > 0 ? $n : (int) (self::SEGMENTS[$key][2] ?? 0);
        $q = User::query()->where('users.is_active', true);

        return match ($key) {
            'new' => $q->where('users.created_at', '>=', now()->subDays(max(1, $n))),
            'most_active' => $q->joinSub(self::visitDays(30), 'v', 'v.user_id', '=', 'users.id')
                ->orderByDesc('v.n')->orderByDesc('users.last_seen_at')->limit(max(1, $n))->select('users.*'),
            'least_active' => $q->leftJoinSub(self::visitDays(30), 'v', 'v.user_id', '=', 'users.id')
                ->where('users.created_at', '<=', now()->subDays(7)) // on laisse une semaine aux nouveaux
                ->orderByRaw('COALESCE(v.n, 0) asc')->orderBy('users.last_seen_at')->limit(max(1, $n))->select('users.*'),
            'one_visit' => $q->whereIn('users.id', DB::table('user_visits')->select('user_id')->groupBy('user_id')->havingRaw('count(*) = 1'))
                ->where(fn ($w) => $w->whereNull('users.last_seen_at')->orWhere('users.last_seen_at', '<=', now()->subDays(max(1, $n)))),
            'inactive' => $q->where(fn ($w) => $w->where('users.last_seen_at', '<=', now()->subDays(max(1, $n)))
                ->orWhere(fn ($x) => $x->whereNull('users.last_seen_at')->where('users.created_at', '<=', now()->subDays(max(1, $n))))),
            'never_played' => $q->where('users.created_at', '<=', now()->subDays(max(0, $n)))
                ->whereNotIn('users.id', CompetitionEntry::where('participant_type', User::class)->select('participant_id')),
            'city' => $q->where('users.city', $city ?: '—'),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /** Identifiants (pour l'envoi). */
    public static function ids(string $key, int $n = 0, ?string $city = null): array
    {
        return self::query($key, $n, $city)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    public static function count(string $key, int $n = 0, ?string $city = null): int
    {
        return count(self::ids($key, $n, $city));
    }

    public static function cities(): array
    {
        return User::query()->whereNotNull('city')->where('city', '!=', '')->select('city', DB::raw('count(*) as n'))
            ->groupBy('city')->orderByDesc('n')->limit(100)->get()->mapWithKeys(fn ($r) => [$r->city => $r->city.' ('.$r->n.')'])->all();
    }
}
