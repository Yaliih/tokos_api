<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que Sosa notifie, réglé dans l'admin (Réglages → Notifications), type par type :
 *  - « app »  : dans la liste des notifications (cloche) ;
 *  - « push » : alerte sur le téléphone (app) et dans le navigateur (site), même Sosa fermé.
 * Par défaut tout est ouvert, sauf les rappels les plus bavards en push.
 */
class NotifyRules
{
    private const KEY = 'notify_rules';

    /** groupe => [type => [libellé, push par défaut]] */
    public const CATALOG = [
        'Défis et duels' => [
            'challenge.received' => ['On te lance un défi', true],
            'challenge.accepted' => ['Ton défi est relevé', true],
            'invitation.declined' => ['Ton invitation est refusée', false],
            'rematch.requested' => ['On te demande une revanche', true],
            'match.prepare' => ['Ton duel va commencer (prépare-toi)', true],
            'match.started' => ['Ton duel commence', true],
            'match.lead' => ['Tu passes en tête / tu perds la tête', false],
            'match.ending_soon' => ['Ton duel se termine bientôt', true],
        'competition.ending_soon' => ['Ta compétition se termine bientôt', true],
            'match.won' => ['Tu as gagné', true],
            'match.lost' => ['Tu as perdu', true],
            'match.draw' => ['Égalité', true],
        ],
        'Concours et tournois' => [
            'entry.submitted' => ['Nouvelle candidature à valider', true],
            'entry.approved' => ['Ta candidature est acceptée', true],
            'entry.rejected' => ['Ta candidature est refusée', true],
            'proposal.submitted' => ['Nouvelle proposition reçue', true],
            'competition.started' => ['La compétition commence', true],
            'competition.completed' => ['La compétition est terminée', true],
            'tournament.next_round' => ['Tu passes au tour suivant', true],
            'tournament.eliminated' => ['Tu es éliminé du tournoi', true],
            'contest.progress' => ['Mon classement bouge (premier, dépassé, dernier, dernières heures)', true],
            'prize.reminder' => ['Rappel : publier la vidéo de la remise du prix (organisateur)', true],
            'prize.delivered' => ['La remise du prix est publiée', true],
        ],
        'Relances (votants et anciens participants)' => [
            'supported.result' => ['Mon favori gagne, est éliminé ou finit à telle place', true],
            'supported.progress' => ['Mon favori est dépassé ou passe premier', true],
            'favorite.joined' => ['Mon favori participe à une nouvelle compétition', true],
            'competition.new' => ['Un nouveau concours ou tournoi est lancé', true],
        ],
        'Social' => [
            'follower.new' => ['Nouvel abonné', false],
            'comment.new' => ['Nouveau commentaire', true],
            'mention' => ['On me cite avec @', true],
            'gift.received' => ['Cadeau reçu', true],
            'badge.earned' => ['Nouveau badge', true],
        ],
        'Espaces' => [
            'space.request' => ['Demande pour rejoindre un de mes Espaces', true],
            'space.invite' => ['Invitation à rejoindre un Espace', true],
            'space.accepted' => ['Ma demande est acceptée', true],
            'space.joined' => ['Quelqu\'un rejoint mon Espace', false],
            'space.role' => ['On me donne un rôle', true],
        ],
        'Annonces' => [
            'announcement' => ['Annonces de l\'équipe Sosa (admin)', true],
        ],
        'Compte' => [
            'verification.approved' => ['Certification acceptée', true],
            'verification.rejected' => ['Certification refusée', true],
        ],
    ];

    public static function flat(): array
    {
        return array_merge(...array_values(self::CATALOG));
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

    /** $channel : app | push. Un type inconnu suit « app : oui, push : oui ». */
    public static function allows(string $type, string $channel): bool
    {
        $saved = self::saved()[$type][$channel] ?? null;
        if ($saved !== null) {
            return (bool) $saved;
        }
        $def = self::flat()[$type] ?? null;

        return $channel === 'push' ? ($def[1] ?? true) : true;
    }

    public static function current(): array
    {
        $out = [];
        foreach (self::flat() as $type => $def) {
            $out[$type] = ['app' => self::allows($type, 'app'), 'push' => self::allows($type, 'push')];
        }

        return $out;
    }

    public static function save(array $values): void
    {
        $clean = [];
        foreach (array_keys(self::flat()) as $type) {
            if (isset($values[$type])) {
                $clean[$type] = ['app' => (bool) ($values[$type]['app'] ?? true), 'push' => (bool) ($values[$type]['push'] ?? false)];
            }
        }
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $clean]);
        Cache::forget('settings.'.self::KEY);
    }

    public static function flush(): void
    {
        Cache::forget('settings.'.self::KEY);
    }
}
