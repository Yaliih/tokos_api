<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Interrupteurs de fonctions : config/features.php donne la liste et les valeurs par défaut,
 * l'admin enregistre les choix dans la table settings (clé « features »).
 */
class Features
{
    private const KEY = 'features';

    private static ?array $memo = null;

    /** Toutes les valeurs, défauts compris. */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $saved = Cache::rememberForever('settings.'.self::KEY, function () {
            try {
                return Schema::hasTable('settings') ? (Setting::find(self::KEY)?->value ?? []) : [];
            } catch (\Throwable) {
                return [];
            }
        });
        $out = [];
        foreach (config('features.list') as $k => $f) {
            $out[$k] = (bool) ($saved[$k] ?? $f['default']);
        }
        // Une fonction qui en demande une autre s'éteint avec elle
        foreach (config('features.list') as $k => $f) {
            if (! empty($f['requires']) && empty($out[$f['requires']])) {
                $out[$k] = false;
            }
        }

        return self::$memo = $out;
    }

    public static function on(string $feature): bool
    {
        return self::all()[$feature] ?? true;
    }

    public static function off(string $feature): bool
    {
        return ! self::on($feature);
    }

    /** Enregistre les choix de l'admin. */
    public static function save(array $values): void
    {
        $clean = [];
        foreach (config('features.list') as $k => $f) {
            $clean[$k] = (bool) ($values[$k] ?? $f['default']);
        }
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $clean]);
        Cache::forget('settings.'.self::KEY);
        self::$memo = null;
    }

    /** Pour les tests. */
    public static function flush(): void
    {
        Cache::forget('settings.'.self::KEY);
        self::$memo = null;
    }

    /** Types de compétition activés. */
    public static function competitionTypes(): array
    {
        return array_values(array_filter(['challenge', 'duel', 'contest', 'tournament'], fn ($t) => self::type($t)));
    }

    /** Le type de compétition est-il activé ? */
    public static function type(string $type): bool
    {
        $map = ['challenge' => 'challenges', 'duel' => 'duels', 'contest' => 'contests', 'tournament' => 'tournaments'];

        return isset($map[$type]) ? self::on($map[$type]) : true;
    }
}
