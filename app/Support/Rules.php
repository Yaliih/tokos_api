<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Règles qui évoluent (durées des médias, poids des fichiers, nombre de participants…).
 * Valeurs de départ dans config/competition.php ; l'admin les change (table settings, clé « rules »).
 * Elles sont posées dans la config au démarrage : le serveur, le site et l'app (via /api/app/…) suivent tout de suite.
 */
class Rules
{
    private const KEY = 'rules';

    /** Réglages modifiables : chemin de config => [libellé, aide, min, max] (min/max null = interrupteur). */
    public static function fields(): array
    {
        return [
            'Médias' => [
                'competition.media.max_video_seconds' => ['Vidéo d\'une performance (secondes)', 'Défis, duels, candidatures, tours de tournoi.', 5, 1800],
                'competition.media.max_audio_seconds' => ['Audio d\'une performance (secondes)', null, 5, 3600],
                'competition.media.max_cover_video_seconds' => ['Vidéo de couverture (secondes)', 'Concours, tournois, profils et Espaces.', 3, 120],
                'competition.media.max_intro_video_seconds' => ['Vidéo de présentation d\'une candidature (secondes)', null, 5, 300],
                'competition.media.max_upload_mb' => ['Poids maximum d\'un fichier (Mo)', 'Le serveur doit aussi l\'accepter : upload_max_filesize et post_max_size dans php.ini.', 1, 500],
            ],
            'Publié en son nom (compte personnel)' => [
                'competition.limits.user.challenge_max_entries' => ['Défi : challengers au plus', null, 2, 100000],
                'competition.limits.user.contest_max_entries' => ['Concours : candidats au plus', null, 2, 100000],
                'competition.limits.user.tournament_max_entries' => ['Tournoi : participants au plus', 'Arrondi à une puissance de 2 (4, 8, 16, 32…).', 4, 2048],
                'competition.limits.user.allow_jury' => ['Jury autorisé', null, null, null],
                'competition.limits.user.allow_prize' => ['Récompenses autorisées', null, null, null],
            ],
            'Publié depuis un Espace' => [
                'competition.limits.space.challenge_max_entries' => ['Défi : challengers au plus', null, 2, 100000],
                'competition.limits.space.contest_max_entries' => ['Concours : candidats au plus', null, 2, 100000],
                'competition.limits.space.tournament_max_entries' => ['Tournoi : participants au plus', 'Arrondi à une puissance de 2.', 4, 2048],
                'competition.limits.space.allow_jury' => ['Jury autorisé', null, null, null],
                'competition.limits.space.allow_prize' => ['Récompenses autorisées', null, null, null],
            ],
            'Durées' => [
                'competition.durations.max_match_hours' => ['Durée maximale d\'un défi, d\'un duel ou d\'un tour (heures)', null, 1, 2160],
                'competition.durations.round_submission_hours' => ['Tournoi : temps pour envoyer sa prestation au tour suivant (heures)', null, 1, 336],
                'competition.durations.invitation_expiry_hours' => ['Une invitation expire après (heures)', null, 1, 720],
            ],
        ];
    }

    /** Liste à plat : chemin => définition. */
    public static function flat(): array
    {
        return array_merge(...array_values(self::fields()));
    }

    public static function saved(): array
    {
        return Cache::rememberForever('settings.'.self::KEY, function () {
            try {
                return Schema::hasTable('settings') ? (Setting::find(self::KEY)?->value ?? []) : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** Pose les valeurs de l'admin dans la config. */
    public static function apply(): void
    {
        foreach (self::saved() as $path => $v) {
            if (array_key_exists($path, self::flat())) {
                config([$path => $v]);
            }
        }
    }

    /** Valeurs actuelles (admin ou défaut). */
    public static function current(): array
    {
        $out = [];
        foreach (self::flat() as $path => $f) {
            $out[$path] = config($path);
        }

        return $out;
    }

    public static function save(array $values): void
    {
        $clean = [];
        foreach (self::flat() as $path => [$label, $help, $min, $max]) {
            if (! array_key_exists($path, $values) || $values[$path] === null || $values[$path] === '') {
                continue;
            }
            if ($min === null) {
                $clean[$path] = (bool) $values[$path];
                continue;
            }
            $v = max($min, min($max, (int) $values[$path]));
            if (str_ends_with($path, 'tournament_max_entries')) {
                $v = 2 ** (int) floor(log($v, 2)); // puissance de 2
            }
            $clean[$path] = $v;
        }
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $clean]);
        Cache::forget('settings.'.self::KEY);
        self::apply();
    }

    /** Pour les tests. */
    public static function flush(): void
    {
        Cache::forget('settings.'.self::KEY);
    }
}
