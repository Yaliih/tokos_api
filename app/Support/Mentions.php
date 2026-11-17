<?php

namespace App\Support;

use App\Models\Space;
use App\Models\User;
use App\Services\Social\NotificationService;
use Illuminate\Database\Eloquent\Model;

/**
 * Mentions « @pseudo » d'une personne ou « @nom-de-l-espace » d'un Espace, dans les publications
 * (description d'une compétition, texte d'une participation) et les commentaires.
 *  - À l'affichage : le nom, en couleur, qui mène au compte ou à l'Espace.
 *  - À la publication : la personne (ou l'équipe de l'Espace) est notifiée.
 * Une personne et un Espace ont le même identifiant : la personne passe d'abord.
 */
class Mentions
{
    public const PATTERN = '/(?<![\w@\/])@([A-Za-z0-9_][A-Za-z0-9_.\-]{1,59})/u';

    /** Au plus tant de personnes notifiées par texte (évite les listes d'@ pour arroser tout le monde). */
    public const MAX_NOTIFIED = 10;

    /** Identifiants cités, en minuscules, sans le point final d'une phrase. @param iterable<?string>|string $texts */
    public static function handles(iterable|string|null $texts): array
    {
        $out = [];
        foreach (is_iterable($texts) ? $texts : [$texts] as $t) {
            if ($t === null || ! str_contains((string) $t, '@')) {
                continue;
            }
            preg_match_all(self::PATTERN, (string) $t, $mm);
            foreach ($mm[1] as $h) {
                $out[mb_strtolower(rtrim($h, '.-'))] = true;
            }
        }

        return array_keys($out);
    }

    /** @return array<string, Model> identifiant => personne ou Espace */
    public static function models(iterable|string|null $texts): array
    {
        $handles = self::handles($texts);
        if (! $handles) {
            return [];
        }
        $out = [];
        foreach (Space::whereIn(\DB::raw('LOWER(slug)'), $handles)->get() as $s) {
            $out[mb_strtolower($s->slug)] = $s;
        }
        foreach (User::whereIn(\DB::raw('LOWER(username)'), $handles)->where('is_active', true)->get() as $u) {
            $out[mb_strtolower($u->username)] = $u; // la personne passe avant l'Espace
        }

        return $out;
    }

    /**
     * Ce qu'il faut pour afficher les mentions (API et site) : identifiant => {type, name, handle, url, avatar_url}.
     *
     * @return array<string, array>
     */
    public static function map(iterable|string|null $texts): array
    {
        return array_map(fn (Model $m) => array_intersect_key(Participant::present($m) ?? [], array_flip(['type', 'name', 'handle', 'url', 'avatar_url'])), self::models($texts));
    }

    /** Le texte découpé : [{type: text|mention, text, url?, handle?}] */
    public static function parts(string $text, ?array $map = null): array
    {
        if (! str_contains($text, '@') || \App\Support\Features::off('mentions')) {
            return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        }
        $map ??= self::map($text);
        $out = [];
        $pos = 0;
        preg_match_all(self::PATTERN, $text, $mm, PREG_OFFSET_CAPTURE);
        foreach ($mm[0] as $i => [$whole, $at]) {
            $handle = rtrim($mm[1][$i][0], '.-');
            $m = $map[mb_strtolower($handle)] ?? null;
            if (! $m) {
                continue;
            }
            if ($at > $pos) {
                $out[] = ['type' => 'text', 'text' => substr($text, $pos, $at - $pos)];
            }
            $m = $m instanceof Model ? Participant::present($m) : $m;
            $out[] = ['type' => 'mention', 'text' => $m['name'] ?? '@'.$handle, 'handle' => $m['handle'] ?? $handle, 'kind' => $m['type'] ?? 'user', 'url' => $m['url'] ?? null];
            $pos = $at + strlen('@'.$handle);
        }
        if ($pos < strlen($text)) {
            $out[] = ['type' => 'text', 'text' => substr($text, $pos)];
        }

        return $out;
    }

    /** HTML sûr pour le site : texte échappé, mentions en liens. */
    public static function html(?string $text, ?array $map = null): string
    {
        $text = (string) $text;
        if (! str_contains($text, '@') || \App\Support\Features::off('mentions')) {
            return e($text);
        }

        return collect(self::parts($text, $map))->map(fn ($p) => $p['type'] === 'mention'
            ? '<a class="mention" href="'.e($p['url']).'">'.e($p['text']).'</a>'
            : e($p['text']))->implode('');
    }

    /**
     * Prévient les personnes citées (et l'équipe des Espaces cités).
     * $where : « dans « Titre » », « en commentaire de « Titre » »…
     */
    public static function notify(?string $text, ?Model $by, string $where, string $url, array $skipUserIds = []): int
    {
        if (\App\Support\Features::off('mentions')) {
            return 0;
        }
        $models = array_slice(self::models($text), 0, self::MAX_NOTIFIED);
        if (! $models || ! $by) {
            return 0;
        }
        $name = Participant::name($by);
        $excerpt = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', (string) $text)), 90);
        $byIds = NotificationService::recipientsFor($by);
        $svc = app(NotificationService::class);
        $n = 0;
        foreach ($models as $m) {
            $ids = array_values(array_diff(NotificationService::recipientsFor($m), $byIds, $skipUserIds));
            if (! $ids) {
                continue;
            }
            $title = $m instanceof Space ? $name.' a cité '.$m->name : $name.' t\'a mentionné';
            $svc->send($ids, 'mention', $title, ucfirst($where).' : « '.$excerpt.' »', $url, $by);
            $n += count($ids);
        }

        return $n;
    }
}
