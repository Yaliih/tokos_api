<?php

namespace App\Services\Competition;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\User;
use App\Services\MediaUploader;
use App\Services\Social\ActivityService;
use App\Services\Social\NotificationService;
use App\Support\MediaUrl;
use App\Support\Mentions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * La remise du prix, dernière étape d'une compétition : l'organisateur publie la vidéo (ou la photo)
 * du gagnant qui reçoit son prix chez le sponsor, avec un mot.
 *  - Elle apparaît sur la page de la compétition (« Remise du prix ») et dans le fil ;
 *  - tous les participants sont prévenus ;
 *  - la compétition porte la mention « Prix remis ».
 * C'est la preuve que les gains sont réels, de la publicité pour le sponsor et l'envie de rejouer.
 */
class PrizeProof
{
    /** Durée maximale de la vidéo (secondes) */
    public const MAX_VIDEO_SECONDS = 180;

    public function __construct(private MediaUploader $uploader, private ActivityService $activities, private NotificationService $notifications)
    {
    }

    public static function canPublish(?User $user, Competition $c): bool
    {
        return $user && $c->status === Competition::STATUS_COMPLETED && ($c->canManage($user) || (bool) $user->is_admin);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function publish(User $by, Competition $c, array $files, ?string $text): Competition
    {
        if (! self::canPublish($by, $c)) {
            throw new CompetitionException('Seul l\'organisateur peut publier la remise du prix, une fois la compétition terminée.', 403);
        }
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile));
        $text = trim((string) $text);
        if (! $files) {
            throw new CompetitionException('Ajoute la vidéo (ou une photo) du gagnant qui reçoit son prix.');
        }
        if (mb_strlen($text) > 500) {
            throw new CompetitionException('Le mot qui accompagne la vidéo fait 500 caractères au plus.');
        }
        $media = $this->uploader->storeMany(array_map(fn ($f) => ['file' => $f], array_slice($files, 0, 4)), 'competitions/prizes', ['video' => self::MAX_VIDEO_SECONDS]);
        if (! $media) {
            throw new CompetitionException('Ce fichier n\'a pas pu être enregistré. Essaie une vidéo MP4 ou une photo.');
        }
        $first = ! $c->prize_proof_at;
        $c->forceFill([
            'prize_proof' => ['media' => $media, 'text' => $text ?: null, 'by' => $by->id],
            'prize_proof_at' => now(),
        ])->save();
        $c->loadMissing(['organizer', 'winnerEntry.participant']);

        if ($first) {
            $winner = $c->winnerEntry;
            $this->activities->record($c->organizer ?? $by, 'prize.delivered', $c, $c->organizerSpace(), [
                'winner_name' => $winner?->displayName(),
                'text' => $text ?: null,
                'media' => $media,
                'competition' => $c->summary(),
            ], 3);
            $this->notifications->send(
                $c->entries()->whereIn('status', ['active', 'winner', 'eliminated'])->get()->flatMap(fn ($e) => $e->stakeholderIds()),
                'prize.delivered', 'Remise du prix',
                ($winner ? $winner->displayName().' a reçu son prix' : 'Le prix a été remis').' : regarde la vidéo de « '.Str::limit($c->title, 40).' ».',
                $c->url().'#remise', $c->organizer, [], $by->id);
            if ($text !== '') {
                Mentions::notify($text, $c->organizer ?? $by, 'dans la remise du prix de « '.Str::limit($c->title, 40).' »', $c->url().'#remise', [$by->id]);
            }
        }

        return $c;
    }

    /** Pour l'affichage (API et site). */
    public static function present(Competition $c): ?array
    {
        $p = $c->prize_proof;
        if (! $c->prize_proof_at || empty($p['media'])) {
            return null;
        }

        return [
            'media' => MediaUrl::list($p['media']),
            'text' => $p['text'] ?? null,
            'mentions' => Mentions::map($p['text'] ?? null) ?: null,
            'at' => $c->prize_proof_at->toIso8601String(),
        ];
    }
}
