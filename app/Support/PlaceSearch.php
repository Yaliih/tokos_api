<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Recherche de lieux pour l'organisateur (zones d'une compétition) : pays, province, territoire, ville, village.
 *
 * Pour ne payer qu'une fois : 1. la même recherche déjà faite → réponse enregistrée ;
 * 2. assez de lieux déjà connus qui commencent par ces lettres → base locale ;
 * 3. sinon seulement, le service de cartes (LocationIQ avec clé, sinon Photon), et on enregistre tout.
 */
class PlaceSearch
{
    public const LEVELS = ['country' => 'Pays', 'state' => 'Province', 'county' => 'Territoire', 'city' => 'Ville', 'locality' => 'Localité'];

    /** @return array<int, array> id, label, context, level, level_label */
    public static function search(string $q): array
    {
        $key = Str::slug(trim($q));
        if (mb_strlen($key) < 2) {
            return [];
        }

        // 1. Même recherche déjà faite (gardée 6 mois)
        $done = DB::table('place_queries')->where('q', $key)->where('updated_at', '>=', now()->subMonths(6))->first();
        if ($done) {
            return self::present(self::byIds(json_decode($done->place_ids, true) ?: []));
        }

        // 2. Lieux déjà connus : assez de réponses sans rien demander à personne
        $local = DB::table('places')->where(fn ($w) => $w->where('search_name', 'like', $key.'%')->orWhere('search_name', 'like', '%-'.$key.'%'))
            ->orderByRaw("CASE WHEN search_name LIKE ? THEN 0 ELSE 1 END", [$key.'%'])->orderByDesc('hits')->limit(6)->get();
        if ($local->count() >= (int) config('places.local_enough', 3)) {
            return self::present($local);
        }

        // 3. Le service de cartes, puis on enregistre
        try {
            $found = config('places.search_provider') === 'locationiq' ? self::locationIq($q) : self::photon($q);
        } catch (\Throwable $e) {
            Log::info('Recherche de lieux indisponible : '.$e->getMessage());

            return self::present($local); // au moins ce qu'on connaît déjà
        }
        $ids = collect($found)->filter()->unique('uid')->take(8)->map(fn ($p) => self::store($p))->values()->all();
        DB::table('place_queries')->updateOrInsert(['q' => $key], ['place_ids' => json_encode($ids), 'updated_at' => now(), 'created_at' => now()]);

        return self::present(self::byIds($ids));
    }

    /** Lieux choisis par l'organisateur (identifiants venus de la recherche) : instantané gardé avec la compétition. */
    public static function zones(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if (! $ids) {
            return [];
        }
        DB::table('places')->whereIn('id', $ids)->increment('hits');

        return self::byIds($ids)->map(fn ($p) => [
            'id' => (int) $p->id, 'label' => $p->label, 'level' => $p->level, 'country' => $p->country,
            'country_code' => $p->country_code, 'state' => $p->state, 'bbox' => json_decode($p->bbox, true),
        ])->all();
    }

    private static function byIds(array $ids)
    {
        $rows = DB::table('places')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $rows[$id] ?? null)->filter()->values();
    }

    private static function present($rows): array
    {
        return collect($rows)->take(6)->map(fn ($p) => [
            'id' => (int) $p->id, 'label' => $p->label, 'context' => $p->context, 'level' => $p->level,
            'level_label' => self::LEVELS[$p->level] ?? 'Lieu',
        ])->values()->all();
    }

    private static function store(array $p): int
    {
        DB::table('places')->updateOrInsert(['uid' => $p['uid']], [
            'label' => $p['label'], 'context' => $p['context'], 'level' => $p['level'], 'country' => $p['country'],
            'country_code' => $p['country_code'], 'state' => $p['state'], 'bbox' => json_encode($p['bbox']),
            'lat' => $p['lat'], 'lng' => $p['lng'], 'search_name' => Str::slug($p['label']), 'updated_at' => now(), 'created_at' => now(),
        ]);

        return (int) DB::table('places')->where('uid', $p['uid'])->value('id');
    }

    private static function level(string $type): string
    {
        return match (true) {
            $type === 'country' => 'country',
            in_array($type, ['state', 'province', 'region'], true) => 'state',
            in_array($type, ['county', 'district'], true) => 'county',
            in_array($type, ['village', 'hamlet', 'locality', 'suburb', 'neighbourhood', 'quarter'], true) => 'locality',
            default => 'city',
        };
    }

    private static function locationIq(string $q): array
    {
        $res = Http::timeout(6)->get('https://api.locationiq.com/v1/autocomplete', [
            'key' => config('places.locationiq_key'), 'q' => $q, 'limit' => 10, 'accept-language' => 'fr', 'normalizecity' => 1,
            'tag' => 'place:country,place:state,place:province,place:county,place:city,place:town,place:village,place:hamlet,place:suburb,place:municipality,boundary:administrative',
        ]);
        if ($res->status() === 404) {
            return []; // LocationIQ répond 404 quand rien ne correspond
        }
        $res->throw();

        return collect($res->json())->map(function ($r) {
            $a = $r['address'] ?? [];
            $bb = $r['boundingbox'] ?? null; // [sud, nord, ouest, est]
            if (! $bb || count($bb) < 4) {
                return null;
            }
            $uid = isset($r['osm_type'], $r['osm_id']) ? 'osm:'.$r['osm_type'].':'.$r['osm_id'] : null;

            return self::item($uid, $r['display_place'] ?? ($a['name'] ?? null), self::level($r['type'] ?? ''), $a['state'] ?? null,
                $a['country'] ?? null, $a['country_code'] ?? null, array_map('floatval', $bb), (float) $r['lat'], (float) $r['lon']);
        })->all();
    }

    private static function photon(string $q): array
    {
        $res = Http::timeout(6)->withHeaders(['User-Agent' => 'Sosa/1.0 ('.config('app.url').')'])
            ->get(config('places.photon_url'), ['q' => $q, 'limit' => 12, 'lang' => 'fr']);
        $res->throw();

        return collect($res->json('features') ?? [])->map(function ($f) {
            $p = $f['properties'] ?? [];
            // Seulement des lieux habités ou administratifs (pas de rues, de magasins…)
            if (! in_array($p['osm_key'] ?? '', ['place', 'boundary'], true) || in_array($p['osm_value'] ?? '', ['house', 'isolated_dwelling', 'island'], true)) {
                return null;
            }
            $type = $p['type'] ?? ($p['osm_value'] ?? '');
            $ext = $p['extent'] ?? null; // [ouest, nord, est, sud]
            [$lng, $lat] = $f['geometry']['coordinates'] ?? [null, null];
            if ($lat === null) {
                return null;
            }
            $bb = $ext ? [(float) $ext[3], (float) $ext[1], (float) $ext[0], (float) $ext[2]] : [(float) $lat - 0.03, (float) $lat + 0.03, (float) $lng - 0.03, (float) $lng + 0.03];
            $uid = isset($p['osm_type'], $p['osm_id']) ? 'osm:'.$p['osm_type'].':'.$p['osm_id'] : null;

            return self::item($uid, $p['name'] ?? null, self::level($p['osm_value'] ?? $type), $p['state'] ?? null, $p['country'] ?? null,
                $p['countrycode'] ?? null, $bb, (float) $lat, (float) $lng);
        })->all();
    }

    private static function item(?string $uid, ?string $name, string $level, ?string $state, ?string $country, ?string $code, array $bb, float $lat, float $lng): ?array
    {
        if (! $name) {
            return null;
        }
        $code = $code ? strtoupper($code) : null;
        $countryName = ($code ? config('places.countries.'.$code) : null) ?: $country;
        $label = $level === 'country' ? ($countryName ?: $name) : $name;
        $context = $level === 'country' ? null : implode(', ', array_filter([$level === 'state' ? null : $state, $countryName]));

        return [
            'uid' => $uid ?: 'n:'.Str::slug($label.'-'.$level.'-'.$code),
            'label' => $label, 'context' => $context ?: null, 'level' => $level, 'country' => $countryName, 'country_code' => $code,
            'state' => $level === 'state' ? $label : $state,
            'bbox' => array_map(fn ($v) => round($v, 5), $bb), 'lat' => round($lat, 5), 'lng' => round($lng, 5),
        ];
    }
}
