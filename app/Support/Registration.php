<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Plafond du nombre de comptes : utile pendant la bêta pour éviter une affluence non maîtrisée.
 * 0 = pas de plafond. Réglé par `php artisan registration:limit 50` (ou dans l'admin).
 */
class Registration
{
    public const KEY = 'registration.limit';

    public static function limit(): int
    {
        try {
            $value = Setting::find(self::KEY)?->value;
        } catch (\Throwable) {
            $value = null;
        }
        if (is_array($value)) {
            $value = $value['max'] ?? null;
        }
        if ($value === null || $value === '') {
            return max(0, (int) env('REGISTRATION_LIMIT', 0));
        }

        return max(0, (int) $value);
    }

    public static function set(int $max): void
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => max(0, $max)]);
    }

    /** Combien de comptes existent (les comptes supprimés ne comptent pas). */
    public static function count(): int
    {
        return (int) User::count();
    }

    public static function open(): bool
    {
        $limit = self::limit();

        return $limit === 0 || self::count() < $limit;
    }

    public static function message(): string
    {
        return 'Les inscriptions sont fermées pour le moment : nous ouvrons les comptes par vagues. Reviens bientôt !';
    }
}