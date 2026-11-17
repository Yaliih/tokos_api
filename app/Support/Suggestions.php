<?php

namespace App\Support;

use App\Models\Space;
use App\Models\User;

/** Personnes à suivre et Espaces à rejoindre, glissés dans le fil (téléphone). */
class Suggestions
{
    /** @return array<int, array> */
    public static function people(?User $me, int $page = 0, int $limit = 8): array
    {
        $exclude = $me ? $me->following()->pluck('users.id')->push($me->id)->all() : [];
        $q = User::query()->where('is_active', true)->whereNotIn('id', $exclude ?: [0])->withCount('followers');
        // D'abord les gens de la même ville ou de la même institution, puis les plus suivis
        if ($me && ($me->school_space_id || $me->city)) {
            $q->orderByRaw('CASE WHEN school_space_id = ? THEN 0 WHEN city = ? THEN 1 ELSE 2 END', [(int) $me->school_space_id, (string) $me->city]);
        }

        return $q->orderByDesc('followers_count')->orderByDesc('id')->skip($page * $limit)->limit($limit)->get()
            ->map(fn (User $u) => Participant::present($u) + [
                'sub' => $u->school_space_id && $me && $u->school_space_id === $me->school_space_id ? 'Même institution'
                    : ($me && $u->city && $u->city === $me->city ? $u->city : $u->followers_count.' abonné'.($u->followers_count > 1 ? 's' : '')),
            ])->all();
    }

    /**
     * Chercher une personne (page « À suivre ») : tout le monde, sauf soi, par nom ou pseudo, les plus suivis d'abord.
     * « following » dit si on la suit déjà (le bouton affiche alors « Abonné »).
     *
     * @return array<int, array>
     */
    public static function searchPeople(?User $me, string $q, int $page = 0, int $limit = 30): array
    {
        $q = trim(ltrim($q, '@'));
        if ($q === '') {
            return [];
        }
        $mine = $me ? array_flip($me->following()->pluck('users.id')->all()) : [];
        $query = User::query()->where('is_active', true)->when($me, fn ($w) => $w->where('id', '!=', $me->id));
        // Chaque mot doit se trouver dans le prénom, le nom ou le pseudo (« esther mw » trouve Esther Mwamba)
        foreach (array_slice(preg_split('/\s+/', $q), 0, 4) as $word) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $word).'%';
            $query->where(fn ($w) => $w->where('username', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }

        return $query
            ->withCount('followers')->orderByDesc('followers_count')->orderBy('id')->skip($page * $limit)->limit($limit)->get()
            ->map(fn (User $u) => Participant::present($u) + [
                'sub' => $u->city ?: $u->followers_count.' abonné'.($u->followers_count > 1 ? 's' : ''),
                'following' => isset($mine[$u->id]),
            ])->all();
    }

    /** @return array<int, array> */
    public static function spaces(?User $me, int $page = 0, int $limit = 8): array
    {
        $mine = $me ? $me->allSpaceIds() : [];

        return Space::query()->where('is_active', true)->whereNotIn('id', $mine ?: [0])->withCount('members')
            ->orderByDesc('is_verified')->orderByDesc('members_count')->skip($page * $limit)->limit($limit)->get()
            ->map(fn (Space $s) => Participant::present($s) + ['sub' => $s->members_count.' membre'.($s->members_count > 1 ? 's' : ''), 'slug' => $s->slug])->all();
    }
}
