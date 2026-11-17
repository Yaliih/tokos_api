<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages des paiements, modifiés dans l'admin (Réglages → Paiements) :
 * clés SasPay (chiffrées), prix du coin, packs, commission, retraits.
 * Ils sont posés dans config('coins.*') au démarrage.
 */
class Payments
{
    private const KEY = 'payments';

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

    public static function apply(): void
    {
        $s = self::saved();
        foreach (['coin_price' => 'coins.coin_price', 'popular' => 'coins.popular', 'commission' => 'coins.commission',
            'cashout_rate' => 'coins.cashout.rate', 'cashout_min' => 'coins.cashout.min'] as $k => $path) {
            if (isset($s[$k]) && $s[$k] !== '') {
                config([$path => (int) $s[$k]]);
            }
        }
        if (! empty($s['pack_sizes']) && is_array($s['pack_sizes'])) {
            config(['coins.pack_sizes' => array_values(array_map('intval', $s['pack_sizes']))]);
        }
        foreach (['saspay_key' => 'coins.saspay.api_key', 'saspay_secret' => 'coins.saspay.webhook_secret'] as $k => $path) {
            if (! empty($s[$k])) {
                try {
                    config([$path => Crypt::decryptString($s[$k])]);
                } catch (\Throwable) {
                    // clé chiffrée avec une autre APP_KEY : on garde celle du .env
                }
            }
        }
        if (filled(config('coins.saspay.api_key'))) {
            config(['coins.provider' => 'saspay']);
        }
        config(['coins.packs' => self::packs()]);
    }

    /** Packs proposés : taille × prix du coin. */
    public static function packs(): array
    {
        $price = max(1, (int) config('coins.coin_price', 20));
        $popular = (int) config('coins.popular');
        $sizes = collect(config('coins.pack_sizes', []))->map(fn ($n) => (int) $n)->filter(fn ($n) => $n > 0)->unique()->sort()->values();
        $best = $sizes->last();

        return $sizes->map(fn ($n) => array_filter([
            'code' => 'p'.$n, 'coins' => $n, 'price' => $n * $price,
            'bonus' => $n === $popular ? 'Populaire' : null,
        ]))->values()->all();
    }

    /** Ce que la page d'admin affiche (les clés ne sont jamais renvoyées en clair). */
    public static function form(): array
    {
        $key = (string) config('coins.saspay.api_key');

        return [
            'coin_price' => (int) config('coins.coin_price'),
            'pack_sizes' => implode(', ', config('coins.pack_sizes', [])),
            'popular' => (int) config('coins.popular'),
            'commission' => (int) config('coins.commission'),
            'cashout_rate' => (int) config('coins.cashout.rate'),
            'cashout_min' => (int) config('coins.cashout.min'),
            'saspay_key' => '', 'saspay_secret' => '',
            'has_key' => $key !== '', 'test_key' => str_starts_with($key, 'sk_test'),
            'has_secret' => filled(config('coins.saspay.webhook_secret')),
        ];
    }

    public static function save(array $v): void
    {
        $s = self::saved();
        foreach (['coin_price', 'popular', 'commission', 'cashout_rate', 'cashout_min'] as $k) {
            if (isset($v[$k]) && $v[$k] !== '') {
                $s[$k] = max(0, (int) $v[$k]);
            }
        }
        if (isset($v['pack_sizes'])) {
            $sizes = collect(preg_split('/[\s,;]+/', (string) $v['pack_sizes'], -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($n) => (int) $n)->filter(fn ($n) => $n > 0 && $n <= 1000000)->unique()->sort()->values()->take(8)->all();
            if ($sizes) {
                $s['pack_sizes'] = $sizes;
            }
        }
        // Une clé vide = on garde l'ancienne ; « effacer » la retire
        foreach (['saspay_key', 'saspay_secret'] as $k) {
            if (! empty($v['clear_'.$k])) {
                unset($s[$k]);
            } elseif (filled($v[$k] ?? null)) {
                $s[$k] = Crypt::encryptString(trim((string) $v[$k]));
            }
        }
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $s]);
        self::flush();
        self::apply();
    }

    public static function flush(): void
    {
        Cache::forget('settings.'.self::KEY);
    }
}
