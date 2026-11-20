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

    public const COUNTRIES = 'registration.countries';

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

    /** Indicatifs autorisés (ex. ['+243', '+242']). Vide = tous les pays. */
    public static function countries(): array
    {
        try {
            $value = Setting::find(self::COUNTRIES)?->value;
        } catch (\Throwable) {
            $value = null;
        }
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }

        return array_values(array_filter(array_map(fn ($c) => trim((string) $c), (array) $value)));
    }

    public static function setCountries(array $codes): void
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $codes))));
        Setting::updateOrCreate(['key' => self::COUNTRIES], ['value' => $codes]);
    }

    /** Ce pays peut-il s'inscrire ? (vide = tous) */
    public static function allowsCountry(?string $code): bool
    {
        $allowed = self::countries();
        if (! $allowed) {
            return true;
        }
        $code = '+'.ltrim((string) $code, '+');

        return in_array($code, array_map(fn ($c) => '+'.ltrim($c, '+'), $allowed), true);
    }

    public static function countryMessage(): string
    {
        return 'Les inscriptions ne sont pas encore ouvertes dans ton pays. Nous arrivons bientôt !';
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