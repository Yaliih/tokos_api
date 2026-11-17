<?php

namespace App\Services\Social;

use App\Models\Activity;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Fil d'activité : enregistre "qui a fait quoi" et construit le fil de chaque utilisateur.
 */
class ActivityService
{
    /** Verbes qu'on ne veut pas dupliquer (ex. suivre / ne plus suivre / suivre). */
    private const DEDUP_VERBS = ['user.followed', 'space.joined', 'post.created'];

    public function record(
        Model $actor,
        string $verb,
        ?Model $subject = null,
        ?Model $target = null,
        array $data = [],
        int $importance = 1,
        string $visibility = 'public'
    ): ?Activity {
        if (in_array($verb, self::DEDUP_VERBS, true) && $subject) {
            $exists = Activity::query()
                ->where('actor_type', $actor::class)->where('actor_id', $actor->getKey())
                ->where('verb', $verb)
                ->where('subject_type', $subject::class)->where('subject_id', $subject->getKey())
                ->where('created_at', '>=', now()->subDay())
                ->exists();
            if ($exists) {
                return null;
            }
        }

        return Activity::create([
            'actor_type' => $actor::class,
            'actor_id' => $actor->getKey(),
            'verb' => $verb,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'target_type' => $target ? $target::class : null,
            'target_id' => $target?->getKey(),
            'data' => $data ?: null,
            'importance' => $importance,
            'visibility' => $visibility,
            'created_at' => now(),
        ]);
    }

    /**
     * Fil personnel : activités des personnes suivies, des Espaces liés, ce qui me concerne,
     * puis les grands événements de la plateforme pour que le fil ne soit jamais vide.
     *
     * @return array{items: Collection, next_cursor: ?int}
     */
    /**
     * Fil d'un utilisateur. Au lancement il y a peu de monde : on montre toute l'activité publique
     * de la plateforme (du plus récent au plus ancien). Ce qui vient de personnes suivies, de ses
     * Espaces ou qui le concerne est affiché normalement ; le reste porte la mention « Populaire »
     * quand c'est important, sans jamais être masqué.
     */
    /** Petites nouvelles : regroupées ou laissées aux notifications, inutile de les tirer pour le fil. */
    private const BRIEF = ['badge.earned', 'rank.top10', 'user.followed', 'space.joined', 'streak'];

    /**
     * Le fil « Pour toi » : d'abord ce que publient les gens que tu suis et tes Espaces,
     * avec une découverte de temps en temps (environ 1 sur 4) pour ne jamais avoir un fil vide.
     * Quand ton réseau n'a plus rien de récent (plus de 3 jours), la découverte passe à 1 sur 2.
     *
     * Deux files lues en parallèle, d'où un curseur double : « n123-d456 » (x = file terminée).
     * Un ancien curseur numérique reste accepté.
     */
    public function feedFor(?User $user, int|string|null $cursor = null, int $limit = 30): array
    {
        $limit = max(5, min(50, $limit));
        [$nAfter, $dAfter] = self::decode($cursor);

        if (! $user) {
            return $this->globalFeed($nAfter ?: null, $limit);
        }

        $followingIds = $user->following()->pluck('users.id')->push($user->id)->map(fn ($v) => (int) $v)->all();
        $spaceIds = array_map('intval', $user->allSpaceIds());

        $network = function ($w) use ($followingIds, $spaceIds, $user) {
            $w->where(fn ($x) => $x->where('actor_type', User::class)->whereIn('actor_id', $followingIds))
                ->orWhere(fn ($x) => $x->where('actor_type', Space::class)->whereIn('actor_id', $spaceIds ?: [0]))
                ->orWhere(fn ($x) => $x->where('target_type', Space::class)->whereIn('target_id', $spaceIds ?: [0]))
                ->orWhere(fn ($x) => $x->where('subject_type', User::class)->where('subject_id', $user->id));
        };

        // 1. Mon réseau (y compris ce qui n'est pas public mais me concerne)
        $mine = $nAfter === false ? collect() : Activity::query()->where('visibility', '!=', 'private')->where($network)
            ->when($nAfter, fn ($q) => $q->where('id', '<', $nAfter))
            ->orderByDesc('id')->limit($limit)->get();

        // 2. Découverte : le reste du public, sans les petites nouvelles
        $discover = $dAfter === false ? collect() : Activity::query()->where('visibility', 'public')
            // (NOT IN plutôt que NOT (…) : les colonnes vides rendraient la condition inconnue en SQL)
            ->whereNotIn('id', fn ($q) => $q->select('id')->from('activities')->where($network)->when($dAfter, fn ($w) => $w->where('id', '<', $dAfter)))
            ->whereNotIn('verb', self::BRIEF)
            ->when($dAfter, fn ($q) => $q->where('id', '<', $dAfter))
            ->orderByDesc('id')->limit($limit)->get()
            ->each(fn (Activity $a) => $a->setAttribute('is_suggested', true));

        $out = new \Illuminate\Database\Eloquent\Collection();
        $mi = $di = 0;
        $slot = 0;
        $stale = now()->subDays(3);
        while ($out->count() < $limit && ($mi < $mine->count() || $di < $discover->count())) {
            $m = $mine[$mi] ?? null;
            $d = $discover[$di] ?? null;
            $every = $m && $m->created_at && $m->created_at->lt($stale) ? 2 : 4; // une découverte toutes les N cartes
            $takeDiscover = $d && (! $m || ++$slot % $every === 0);
            if ($takeDiscover) {
                $out->push($d);
                $di++;
            } else {
                $out->push($m);
                $mi++;
            }
        }

        $next = function ($lane, int $used, $after) use ($limit) {
            if ($used === 0) {
                return $lane->isEmpty() ? false : $after;          // rien pris : on reprend au même endroit
            }

            return $used >= $lane->count() && $lane->count() < $limit ? false : (int) $lane[$used - 1]->id;
        };
        $n = $next($mine, $mi, $nAfter);
        $d = $next($discover, $di, $dAfter);

        $out->load(['actor', 'subject', 'target']);

        return [
            'items' => $out->values(),
            'next_cursor' => ($n === false && $d === false) ? null : self::encode($n, $d),
        ];
    }

    /** @return array{0: int|false|null, 1: int|false|null} null = depuis le début, false = file terminée */
    public static function decode(int|string|null $cursor): array
    {
        if ($cursor === null || $cursor === '' || $cursor === 0 || $cursor === '0') {
            return [null, null];
        }
        if (is_int($cursor) || ctype_digit((string) $cursor)) {
            return [(int) $cursor, (int) $cursor];
        }
        if (! preg_match('/^n(x|\d*)-d(x|\d*)$/', (string) $cursor, $m)) {
            return [null, null];
        }
        $v = fn ($s) => $s === 'x' ? false : ($s === '' ? null : (int) $s);

        return [$v($m[1]), $v($m[2])];
    }

    public static function encode(int|false|null $n, int|false|null $d): string
    {
        $v = fn ($x) => $x === false ? 'x' : ($x === null ? '' : (string) $x);

        return 'n'.$v($n).'-d'.$v($d);
    }

    public function globalFeed(?int $cursor = null, int $limit = 30): array
    {
        $query = Activity::query()->where('visibility', 'public');
        if ($cursor) {
            $query->where('id', '<', $cursor);
        }

        return $this->finalize($query->orderByDesc('id')->limit($limit)->get(), $limit);
    }

    /** Activités d'un acteur (profil utilisateur ou page d'Espace). */
    public function forActor(Model $actor, ?int $cursor = null, int $limit = 30): array
    {
        $query = Activity::query()
            ->where('visibility', 'public')
            ->where(function (Builder $q) use ($actor) {
                $q->where(fn ($a) => $a->where('actor_type', $actor::class)->where('actor_id', $actor->getKey()));
                if ($actor instanceof Space) {
                    $q->orWhere(fn ($a) => $a->where('target_type', Space::class)->where('target_id', $actor->getKey()));
                }
            });
        if ($cursor) {
            $query->where('id', '<', $cursor);
        }

        return $this->finalize($query->orderByDesc('id')->limit($limit)->get(), $limit);
    }

    private function finalize(Collection $items, int $limit): array
    {
        $items->load(['actor', 'subject', 'target']);
        $minId = $items->min('id');

        return [
            'items' => $items,
            'next_cursor' => $items->count() >= $limit ? $minId : null,
        ];
    }
}
