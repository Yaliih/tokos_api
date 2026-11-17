<?php

namespace App\Support;

use App\Models\CompetitionMatch;
use App\Models\PostComment;
use Illuminate\Support\Collection;

/**
 * Le texte d'un commentaire, découpé pour l'affichage (site et app) :
 *   - « @pseudo » d'un membre ou d'un Espace → son nom, en couleur, qui mène à son compte (voir Mentions) ;
 *   - le duel visé (un défi en a plusieurs) → « X contre Y », qui mène au duel.
 */
class CommentText
{
    /** Les personnes et Espaces cités dans plusieurs commentaires, en une seule recherche. @param iterable<string> $texts */
    public static function people(iterable $texts): array
    {
        return Mentions::map($texts);
    }

    /** @return array<int, array{type: string, text: string, url?: string}> */
    public static function parts(string $text, ?array $people = null): array
    {
        return Mentions::parts($text, $people);
    }

    /** Le duel visé : « X contre Y ». */
    public static function duel(?CompetitionMatch $m): ?array
    {
        if (! $m) {
            return null;
        }
        $m->loadMissing(['entryA', 'entryB']);
        $a = $m->entryA?->displayName() ?? '?';
        $b = $m->entryB?->displayName() ?? '?';

        return ['id' => $m->id, 'url' => $m->url(), 'a' => $a, 'b' => $b, 'label' => $a.' contre '.$b];
    }

    /** HTML sûr pour le site : texte échappé, mentions en liens. */
    public static function html(string $text, ?array $people = null): string
    {
        return Mentions::html($text, $people);
    }

    /** Tous les commentaires d'une liste (avec leurs réponses), pour une seule recherche des membres cités. */
    public static function peopleFor(Collection $comments): array
    {
        return self::people($comments->flatMap(fn ($c) => [$c->content, ...($c->relationLoaded('replies') ? $c->replies->pluck('content') : [])]));
    }
}
