<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Chiffres d'usage pour l'admin : actifs, retours, rétention, ancienneté de la dernière visite. */
class UsageStats
{
    /** Personnes distinctes venues entre deux dates (incluses). */
    public static function active(Carbon $from, ?Carbon $to = null): int
    {
        return (int) DB::table('user_visits')->whereBetween('day', [$from->toDateString(), ($to ?? now())->toDateString()])->distinct()->count('user_id');
    }

    public static function overview(): array
    {
        return Cache::remember('stats.overview', 300, function () {
            $dau = self::active(now());
            $wau = self::active(now()->subDays(6));
            $mau = self::active(now()->subDays(29));
            // Revenus : actifs ces 30 jours qui sont venus au moins 2 jours différents
            $returning = (int) DB::query()->fromSub(
                DB::table('user_visits')->where('day', '>=', now()->subDays(29)->toDateString())
                    ->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) >= 2'), 'r')->count();
            $total = User::count();

            return [
                'dau' => $dau, 'wau' => $wau, 'mau' => $mau, 'total' => $total,
                'stickiness' => $mau ? round($dau / $mau * 100) : 0,
                'returning' => $returning,
                'returning_rate' => $mau ? round($returning / $mau * 100) : 0,
                'new_30' => User::where('created_at', '>=', now()->subDays(29)->startOfDay())->count(),
                'never_back' => User::where('created_at', '<', now()->subDay())->whereNotExists(fn ($q) => $q->from('user_visits')
                    ->whereColumn('user_visits.user_id', 'users.id')->whereRaw('user_visits.day > DATE(users.created_at)'))->count(),
            ];
        });
    }

    /** Actifs par jour et inscriptions par jour, sur N jours. */
    public static function daily(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->toDateString();
        $active = DB::table('user_visits')->where('day', '>=', $from)->select('day', DB::raw('COUNT(*) as n'))->groupBy('day')->pluck('n', 'day');
        $signups = User::where('created_at', '>=', $from)->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as n'))->groupBy('d')->pluck('n', 'd');
        $labels = $a = $s = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $labels[] = $d->locale('fr')->translatedFormat('j M');
            $a[] = (int) ($active[$d->toDateString()] ?? 0);
            $s[] = (int) ($signups[$d->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'active' => $a, 'signups' => $s];
    }

    /** Actions par jour (votes, participations, commentaires, compétitions créées). */
    public static function actions(int $days = 14): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $count = fn (string $table) => DB::table($table)->where('created_at', '>=', $from)
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as n'))->groupBy('d')->pluck('n', 'd');
        $series = [
            'Votes' => $count('competition_votes'),
            'Participations' => $count('competition_entries'),
            'Commentaires' => $count('post_comments'),
            'Compétitions' => $count('competitions'),
        ];
        $labels = [];
        $out = array_fill_keys(array_keys($series), []);
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $labels[] = $d->locale('fr')->translatedFormat('j M');
            foreach ($series as $name => $rows) {
                $out[$name][] = (int) ($rows[$d->toDateString()] ?? 0);
            }
        }

        return ['labels' => $labels, 'series' => $out];
    }

    /** Depuis quand on n'a pas vu chaque personne (dernier jour de visite). */
    public static function recency(): array
    {
        $last = DB::table('user_visits')->select('user_id', DB::raw('MAX(day) as last_day'))->groupBy('user_id')->pluck('last_day', 'user_id');
        $today = now()->toDateString();
        $out = ['Aujourd\'hui' => 0, '1 à 7 jours' => 0, '8 à 30 jours' => 0, 'Plus de 30 jours' => 0, 'Jamais venu' => 0];
        foreach (User::pluck('id') as $id) {
            $day = $last[$id] ?? null;
            if (! $day) {
                $out['Jamais venu']++;

                continue;
            }
            $ago = Carbon::parse($day)->diffInDays(Carbon::parse($today));
            $key = $ago < 1 ? 'Aujourd\'hui' : ($ago <= 7 ? '1 à 7 jours' : ($ago <= 30 ? '8 à 30 jours' : 'Plus de 30 jours'));
            $out[$key]++;
        }

        return $out;
    }

    /**
     * Rétention par semaine d'inscription : part des inscrits revenus
     * le lendemain (J1), la 2e semaine (J7 à J13) et le 2e mois (J30 à J59).
     */
    public static function retention(int $weeks = 8): array
    {
        return Cache::remember('stats.retention.'.$weeks, 600, function () use ($weeks) {
            $rows = [];
            for ($w = $weeks - 1; $w >= 0; $w--) {
                $start = now()->startOfWeek()->subWeeks($w);
                $end = $start->copy()->endOfWeek();
                $users = User::whereBetween('created_at', [$start, $end])->pluck('created_at', 'id');
                $cohort = $users->count();
                $back = function (int $fromDay, int $toDay) use ($users, $end) {
                    if (now()->lt($end->copy()->addDays($fromDay))) {
                        return null; // pas encore mesurable
                    }
                    $n = 0;
                    foreach ($users as $id => $created) {
                        $c = Carbon::parse($created)->startOfDay();
                        if (DB::table('user_visits')->where('user_id', $id)
                            ->whereBetween('day', [$c->copy()->addDays($fromDay)->toDateString(), $c->copy()->addDays($toDay)->toDateString()])->exists()) {
                            $n++;
                        }
                    }

                    return $n;
                };
                $pct = fn (?int $n) => $n === null || ! $cohort ? null : (int) round($n / $cohort * 100);
                $rows[] = [
                    'week' => 'Semaine du '.$start->locale('fr')->translatedFormat('j M'),
                    'users' => $cohort,
                    'd1' => $cohort ? $pct($back(1, 1)) : null,
                    'd7' => $cohort ? $pct($back(7, 13)) : null,
                    'd30' => $cohort ? $pct($back(30, 59)) : null,
                ];
            }

            return array_reverse($rows);
        });
    }
}
