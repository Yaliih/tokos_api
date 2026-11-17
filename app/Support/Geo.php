<?php

namespace App\Support;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Ville d'une personne d'après la position de son téléphone, et compétitions réservées à une ville.
 * On ne garde que le nom de la ville et l'heure de la vérification, jamais les coordonnées.
 */
class Geo
{
    public const SCOPES = ['enter' => 'Pour participer', 'vote' => 'Pour voter', 'both' => 'Pour participer et voter'];

    /**
     * Où est la personne : ['city' => ?string, 'country' => ?string, 'country_code' => ?string].
     * $hint : ce que le téléphone a déjà trouvé (OpenStreetMap appelé depuis le navigateur), vérifié ici.
     */
    public static function locate(float $lat, float $lng, array $hint = []): array
    {
        $city = null; $code = null; $country = null; $state = $hint['state'] ?? null; $county = $hint['county'] ?? null;

        // 1. Ce que le téléphone a trouvé : gardé si c'est cohérent avec la position
        if (! empty($hint['city'])) {
            $c = self::canonical($hint['city']);
            $known = config('places.coords.'.$c);
            if (! $known || self::km($lat, $lng, $known[0], $known[1]) <= $known[2] + 60) {
                $city = $c;
            }
            // Nom inconnu (souvent un quartier ou un village) alors qu'une ville connue est à deux pas : on garde la ville
            $near = $known ? null : self::nearest($lat, $lng, 12.0);
            if ($near) {
                $city = $near;
            }
        }
        if (! empty($hint['country_code'])) {
            $code = strtoupper(substr($hint['country_code'], 0, 2));
            $country = config('places.countries.'.$code) ?: ($hint['country'] ?? null);
        }

        // 2. Sinon le serveur demande à OpenStreetMap
        $geocoder = config('places.geocoder');
        // Déjà traduit pour ce carré d'environ 1 km : on ne redemande pas (et on ne repaie pas)
        $cellKey = round($lat, 2).':'.round($lng, 2);
        $cell = (! $city || ! $state || ! $code) ? \Illuminate\Support\Facades\DB::table('geo_cells')->where('cell', $cellKey)->first() : null;
        if ($cell) {
            $city = $city ?: $cell->city; $state = $state ?: $cell->state; $county = $county ?: $cell->county;
            if (! $code && $cell->country_code) { $code = $cell->country_code; $country = config('places.countries.'.$code) ?: $cell->country; }
        }
        if (! $cell && (! $city || ! $state || ! $code) && in_array($geocoder, ['nominatim', 'locationiq'], true)) {
            try {
                // LocationIQ (avec clé) ou OpenStreetMap Nominatim : même format de réponse
                $iq = $geocoder === 'locationiq' && config('places.locationiq_key');
                $res = Http::timeout(5)->withHeaders(['User-Agent' => 'Sosa/1.0 ('.config('app.url').')', 'Accept-Language' => 'fr'])
                    ->get($iq ? 'https://us1.locationiq.com/v1/reverse' : config('places.nominatim_url'), array_filter([
                        'key' => $iq ? config('places.locationiq_key') : null, 'format' => $iq ? 'json' : 'jsonv2',
                        'lat' => $lat, 'lon' => $lng, 'zoom' => 10, 'addressdetails' => 1, 'accept-language' => 'fr',
                    ]));
                $a = $res->successful() ? ($res->json('address') ?? []) : [];
                $name = $a['city'] ?? $a['town'] ?? $a['municipality'] ?? $a['county'] ?? $a['village'] ?? null;
                $city = $city ?: ($name ? self::canonical($name) : null);
                $state = $state ?: ($a['state'] ?? null);
                $county = $county ?: ($a['county'] ?? null);
                if (! $code && ! empty($a['country_code'])) {
                    $code = strtoupper($a['country_code']);
                    $country = config('places.countries.'.$code) ?: ($a['country'] ?? null);
                }
            } catch (\Throwable $e) {
                // hors ligne, ou certificat refusé (fréquent sous Windows) : la table locale prend le relais
                \Illuminate\Support\Facades\Log::info('Géolocalisation OpenStreetMap indisponible : '.$e->getMessage());
            }
            if ($city || $state || $code) {
                \Illuminate\Support\Facades\DB::table('geo_cells')->updateOrInsert(['cell' => $cellKey], ['city' => $city, 'county' => $county, 'state' => $state,
                    'country' => $country, 'country_code' => $code, 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        // 3. Table locale : la ville proche, sinon au moins le pays
        if (! $city) {
            $city = self::nearest($lat, $lng);
        }
        if (! $code) {
            $ref = $city && config('places.coords.'.$city) ? $city : self::nearest($lat, $lng, (float) config('places.country_max_km'));
            if ($ref && config('places.coords.'.$ref)) {
                $code = config('places.city_country.'.$ref, 'CD');
                $country = config('places.countries.'.$code);
            }
        }

        return ['city' => $city, 'country' => $country, 'country_code' => $code, 'state' => $state, 'county' => $county];
    }

    /** Ancien appel : la ville seule. */
    public static function cityAt(float $lat, float $lng): ?string
    {
        return self::locate($lat, $lng)['city'];
    }

    /** La ville connue la plus proche, en tenant compte de sa taille. */
    public static function nearest(float $lat, float $lng, ?float $maxKm = null): ?string
    {
        $best = null;
        $bestScore = INF;
        foreach (config('places.coords') as $city => [$clat, $clng, $radius]) {
            $km = self::km($lat, $lng, $clat, $clng);
            if ($km > ($maxKm ?? config('places.max_km')) + $radius) {
                continue;
            }
            $score = $km / max(1, $radius);
            if ($score < $bestScore) {
                [$best, $bestScore] = [$city, $score];
            }
        }

        return $best;
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** Écrit la ville comme dans notre liste (« kinshasa », « Ville de Kinshasa » → « Kinshasa »). */
    public static function canonical(string $name): string
    {
        $slug = Str::slug($name);
        foreach (config('places.cities') as $c) {
            $cs = Str::slug($c);
            if ($slug === $cs || str_ends_with($slug, '-'.$cs) || str_starts_with($slug, $cs.'-')) {
                return $c;
            }
        }

        return trim($name);
    }

    public static function same(?string $a, ?string $b): bool
    {
        return $a && $b && Str::slug(self::canonical($a)) === Str::slug(self::canonical($b));
    }

    /** Ville confirmée par la position ces derniers jours (affichage « vérifiée » sur le profil). */
    public static function fresh(?User $u): bool
    {
        return $u && $u->city_located_at && $u->city_located_at->gt(now()->subHours(config('places.located_valid_hours')));
    }

    /**
     * Position de l'appareil prise à l'instant (quelques minutes) : [lat, lng, accuracy, country_code, city].
     * L'app l'envoie avec sa demande (lat, lng, accuracy) ; le site la garde le temps de la session, jamais en base.
     */
    public static function position(): ?array
    {
        $r = request();
        if ($r && $r->filled(['lat', 'lng', 'accuracy']) && is_numeric($r->input('lat')) && is_numeric($r->input('lng'))) {
            return ['lat' => (float) $r->input('lat'), 'lng' => (float) $r->input('lng'), 'accuracy' => (float) $r->input('accuracy'),
                'country_code' => null, 'city' => null, 'at' => now()->timestamp, 'from_request' => true];
        }
        $p = ($r && $r->hasSession()) ? $r->session()->get('geo_pos') : null;

        return $p && ($p['at'] ?? 0) >= now()->subMinutes(config('places.action_valid_minutes'))->timestamp ? $p : null;
    }

    /** Garde la position du moment (session seulement). */
    public static function remember(float $lat, float $lng, float $accuracy, ?string $countryCode, ?string $city, ?string $state = null, ?string $county = null): void
    {
        if (request()->hasSession()) {
            request()->session()->put('geo_pos', ['lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy,
                'country_code' => $countryCode, 'city' => $city, 'state' => $state, 'county' => $county, 'at' => now()->timestamp]);
        }
    }

    /** Complète une position (venue de l'app) avec son pays et sa province, si on en a besoin. */
    private static function enrich(array $pos): array
    {
        if (empty($pos['country_code']) || empty($pos['state']) || empty($pos['county'])) {
            $w = self::locate($pos['lat'], $pos['lng']);
            $pos['country_code'] = $pos['country_code'] ?: $w['country_code'];
            $pos['state'] = $w['state'];
            $pos['county'] = $pos['county'] ?? $w['county'];
            $pos['city'] = $pos['city'] ?: $w['city'];
        }

        return $pos;
    }

    /** « Kongo-Central », « Province du Kongo Central » → « kongo-central » */
    private static function key(?string $name): string
    {
        $s = \Illuminate\Support\Str::slug((string) $name);

        return trim(preg_replace('/^(province-du-|province-de-|province-|ville-de-|ville-province-de-)/', '', $s), '-');
    }

    /** Ancien nom : une position de l'appareil est-elle disponible ? */
    public static function freshForAction(?User $u): bool
    {
        return $u !== null && self::position() !== null;
    }

    /** Nom de la zone d'une compétition (ville, province ou pays). */
    public static function zone(Competition $c): ?string
    {
        $zones = self::zones($c);
        if ($zones) {
            $labels = array_column($zones, 'label');

            return count($labels) > 3 ? implode(', ', array_slice($labels, 0, 3)).' et '.(count($labels) - 3).' autres' : self::joinFr($labels);
        }

        return $c->geo_city ?: $c->geo_country;
    }

    /** Zones choisies par l'organisateur (plusieurs possibles). */
    public static function zones(Competition $c): array
    {
        $z = is_array($c->geo_zones) ? $c->geo_zones : json_decode((string) $c->geo_zones, true);

        return is_array($z) ? $z : [];
    }

    private static function joinFr(array $l): string
    {
        return count($l) > 1 ? implode(', ', array_slice($l, 0, -1)).' ou '.end($l) : (string) ($l[0] ?? '');
    }

    /** Une position est-elle dans une zone (pays, province, territoire : par le nom ; ville, localité : par le contour) ? */
    private static function inPlace(array $pos, array $z): bool
    {
        $cc = fn () => empty($z['country_code']) || empty($pos['country_code']) || strtoupper($pos['country_code']) === strtoupper($z['country_code']);
        if (in_array($z['level'], ['country', 'state', 'county'], true)) {
            $pos = self::enrich($pos);
            if ($z['level'] === 'country' && ! empty($pos['country_code'])) {
                return strtoupper($pos['country_code']) === strtoupper((string) $z['country_code']);
            }
            $field = $z['level'] === 'state' ? 'state' : 'county';
            if (! empty($pos[$field])) {
                return self::key($pos[$field]) === self::key($z['label']) && $cc();
            }
        }
        $bb = $z['bbox'] ?? null;
        if (! $bb || count($bb) !== 4) {
            return false;
        }
        [$s, $n, $w, $e] = array_map('floatval', $bb);
        $marginKm = (float) config('places.zone_margin_km') + min(5.0, ($pos['accuracy'] ?? 0) / 1000);
        $dLat = $marginKm / 111.0;
        $dLng = $marginKm / max(1.0, 111.0 * cos(deg2rad(($s + $n) / 2)));

        return $pos['lat'] >= $s - $dLat && $pos['lat'] <= $n + $dLat && $pos['lng'] >= $w - $dLng && $pos['lng'] <= $e + $dLng;
    }

    /** La compétition est-elle limitée à une zone pour cette action (enter | vote) ? */
    public static function limits(Competition $c, string $action): bool
    {
        return (bool) self::zone($c) && in_array($c->geo_scope ?: 'both', [$action, 'both'], true);
    }

    /**
     * La position est-elle dans la zone ? Pays : même code pays (ou, à défaut, dans son contour).
     * Ville ou province : dans le contour, avec une marge (bord de la zone + précision de la position).
     */
    public static function inZone(?array $pos, Competition $c, ?User $u = null): bool
    {
        $bb = is_array($c->geo_bbox) ? $c->geo_bbox : json_decode((string) $c->geo_bbox, true);
        if (! $pos) {
            return false;
        }
        // Plusieurs zones : il suffit d'être dans l'une d'elles
        if ($zones = self::zones($c)) {
            foreach ($zones as $z) {
                if (self::inPlace($pos, $z)) {
                    return true;
                }
            }

            return false;
        }
        // Pays et province : on compare les noms donnés par la carte (un rectangle déborderait sur les voisins)
        if ($c->geo_level === 'country' && $c->geo_country_code) {
            $pos = self::enrich($pos);
            if (! empty($pos['country_code'])) {
                return strtoupper($pos['country_code']) === strtoupper($c->geo_country_code);
            }
        }
        if ($c->geo_level === 'state' && $c->geo_city) {
            $pos = self::enrich($pos);
            if (! empty($pos['state'])) {
                return self::key($pos['state']) === self::key($c->geo_city)
                    && (! $c->geo_country_code || empty($pos['country_code']) || strtoupper($pos['country_code']) === strtoupper($c->geo_country_code));
            }
        }
        if ($bb && count($bb) === 4) {
            [$s, $n, $w, $e] = array_map('floatval', $bb);
            $marginKm = (float) config('places.zone_margin_km') + min(5.0, ($pos['accuracy'] ?? 0) / 1000);
            $dLat = $marginKm / 111.0;
            $dLng = $marginKm / max(1.0, 111.0 * cos(deg2rad(($s + $n) / 2)));

            return $pos['lat'] >= $s - $dLat && $pos['lat'] <= $n + $dLat && $pos['lng'] >= $w - $dLng && $pos['lng'] <= $e + $dLng;
        }
        // Anciennes compétitions (ville seule, sans contour) : la ville de la position
        $city = $pos['city'] ?? self::nearest($pos['lat'], $pos['lng']);

        return $c->geo_city ? self::same($city, $c->geo_city) : ($c->geo_country && $u && $u->country === $c->geo_country);
    }

    /** Refuse si la personne n'a pas donné sa position, si elle est imprécise, ou hors de la zone. */
    public static function assert(User $u, Competition $c, string $action): void
    {
        if (! self::limits($c, $action) || Features::off('geo_limits')) {
            return;
        }
        $verb = $action === 'vote' ? 'voter' : 'participer';
        $zone = self::zone($c);
        $pos = self::position();
        if (! $pos) {
            throw new CompetitionException('Réservé à '.$zone.' : active ta position pour '.$verb.'.', 428, 'location_required');
        }
        if (($pos['accuracy'] ?? INF) > config('places.max_accuracy_m')) {
            throw new CompetitionException('Position trop imprécise : active la localisation (GPS) de ton appareil pour '.$verb.'.', 422, 'imprecise');
        }
        if (! self::inZone($pos, $c, $u)) {
            throw new CompetitionException('Réservé aux personnes à '.$zone.'. Ta position est en dehors.', 403, 'wrong_city');
        }
    }
}
