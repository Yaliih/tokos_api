<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Les disciplines (Chant, Danse, Sciences…), réglées dans l'admin (Réglages → Disciplines).
 * Elles sont dans la base, pas dans le code : la liste de départ est posée par la migration
 * 2026_11_09_000001_store_categories_in_admin (clé « categories_default »).
 *
 * Au démarrage, la liste de l'admin remplace config('competition.categories') : tout le site et l'API
 * l'utilisent sans rien changer. Une discipline désactivée disparaît des choix, mais garde son nom
 * sur les anciennes compétitions (config('competition.category_labels')).
 */
class Categories
{
    private const KEY = 'categories';

    private const DEFAULT_KEY = 'categories_default';

    private static function read(string $key): ?array
    {
        $v = Cache::rememberForever('settings.'.$key, function () use ($key) {
            try {
                return Schema::hasTable('settings') ? (Setting::find($key)?->value ?? null) : null;
            } catch (\Throwable) {
                return null;
            }
        });

        return is_array($v) && $v ? array_values($v) : null;
    }

    /** La liste réglée dans l'admin (sinon la liste de départ). @return array<int, array{key: string, label: string, active: bool}> */
    public static function list(): array
    {
        return self::read(self::KEY) ?? self::defaults();
    }

    /** La liste de départ, enregistrée dans la base. @return array<int, array{key: string, label: string, active: bool}> */
    public static function defaults(): array
    {
        return self::read(self::DEFAULT_KEY) ?? [];
    }

    /** À relire après une migration ou un changement dans l'admin. */
    public static function refresh(): void
    {
        Cache::forget('settings.'.self::KEY);
        Cache::forget('settings.'.self::DEFAULT_KEY);
        self::apply();
    }

    public static function apply(): void
    {
        $list = self::list();
        config([
            'competition.categories' => collect($list)->where('active', true)->pluck('label', 'key')->all(),
            // Les noms restent connus même pour une discipline désactivée ou retirée
            'competition.category_labels' => collect($list)->pluck('label', 'key')->all() + collect(self::defaults())->pluck('label', 'key')->all(),
        ]);
    }

    public static function label(?string $key): ?string
    {
        if (! $key) {
            return null;
        }
        $key = config('competition.category_aliases.'.$key, $key);

        return config('competition.category_labels.'.$key) ?? Str::ucfirst($key);
    }

    /**
     * Enregistre la liste de l'admin. Une nouvelle ligne reçoit sa clé à partir du nom.
     * Une discipline retirée mais encore utilisée (compétitions, goûts) est gardée, désactivée.
     *
     * @return array{saved: int, kept: string[]}
     */
    public static function save(array $rows): array
    {
        $old = collect(self::list())->keyBy('key');
        $used = [];
        $out = [];
        foreach ($rows as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = (string) ($r['key'] ?? '');
            if ($key === '' || isset($used[$key])) {
                $base = Str::limit(Str::slug($label, ''), 20, '') ?: 'discipline';
                $key = $base;
                for ($i = 2; isset($used[$key]) || ($old->has($key) && ! collect($rows)->contains(fn ($x) => ($x['key'] ?? null) === $key)); $i++) {
                    $key = $base.$i;
                }
            }
            $used[$key] = true;
            $out[] = ['key' => $key, 'label' => mb_substr($label, 0, 40), 'active' => (bool) ($r['active'] ?? true)];
        }
        // Retirées mais encore utilisées : gardées, désactivées
        $kept = [];
        foreach ($old as $key => $r) {
            if (isset($used[$key])) {
                continue;
            }
            $inUse = DB::table('competitions')->where('category', $key)->exists()
                || DB::table('users')->where('interests', 'like', '%"'.$key.'"%')->exists();
            if ($inUse) {
                $out[] = ['key' => $key, 'label' => $r['label'], 'active' => false];
                $kept[] = $r['label'];
            }
        }
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $out]);
        self::refresh();

        return ['saved' => count($out), 'kept' => $kept];
    }

    /** « Remettre la liste par défaut » : la liste de départ enregistrée dans la base. */
    public static function reset(): void
    {
        $start = self::defaults();
        if (! $start) {
            return;
        }
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $start]);
        self::refresh();
    }
}
