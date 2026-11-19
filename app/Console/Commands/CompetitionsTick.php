<?php

namespace App\Console\Commands;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\CompetitionInvitation;
use App\Models\CompetitionMatch;
use App\Services\Competition\CompetitionService;
use App\Services\Competition\MatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Horloge du moteur de compétition (à lancer chaque minute par le scheduler) :
 *  - clôture les duels arrivés à échéance et désigne les gagnants
 *  - prévient les participants une heure avant la fin
 *  - lance les concours / tournois à la date prévue
 *  - termine les concours et les défis arrivés à échéance
 *  - expire les défis non acceptés
 *  - fait baisser le score "tendance" pour que le classement reste frais
 */
class CompetitionsTick extends Command
{
    protected $signature = 'competitions:tick';

    protected $description = 'Fait avancer les compétitions (clôtures, lancements, rappels).';

    public function handle(MatchService $matches, CompetitionService $competitions): int
    {
        // 1. Duels terminés
        CompetitionMatch::where('status', 'voting')->where('ends_at', '<=', now())
            ->orderBy('ends_at')->limit(500)->get()
            ->each(fn ($m) => $this->safely(fn () => $matches->close($m)));

        // 1 bis. Tournoi : délai d'envoi des prestations écoulé (l'absent perd), et rappel avant la fin
        CompetitionMatch::where('status', 'preparing')->where('submit_by', '<=', now())->limit(500)->get()
            ->each(fn ($m) => $this->safely(fn () => $matches->resolvePreparation($m)));
        CompetitionMatch::where('status', 'preparing')->where('ending_soon_notified', false)
            ->where('submit_by', '<=', now()->addHours((int) config('competition.durations.round_reminder_hours', 4)))
            ->where(fn ($q) => $q->whereNull('a_submitted_at')->orWhereNull('b_submitted_at'))
            ->limit(500)->get()->each(fn ($m) => $this->safely(fn () => $matches->remindPreparation($m)));

        // 2. Rappel "plus qu'une heure"
        CompetitionMatch::where('status', 'voting')->where('ending_soon_notified', false)
            ->whereBetween('ends_at', [now(), now()->addMinutes((int) config('competition.durations.ending_soon_minutes', 60))])
            ->where('created_at', '<=', now()->subHours(2))
            ->limit(500)->get()
            ->each(fn ($m) => $this->safely(fn () => $matches->notifyEndingSoon($m)));

        // 2 ter. Relance des compétitions qui se terminent (défi, concours, tournoi)
        $this->remindCompetitions();

        // 3. Lancements programmés (concours et tournois)
        Competition::where('status', Competition::STATUS_OPEN)
            ->whereIn('type', [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT])
            ->where(fn ($q) => $q->where('starts_at', '<=', now())->orWhere('registration_ends_at', '<=', now()))
            ->limit(100)->get()
            ->each(function (Competition $c) use ($competitions) {
                try {
                    $competitions->startNow($c);
                } catch (CompetitionException $e) {
                    // Pas assez de participants à l'heure prévue : on annule proprement.
                    $c->update(['status' => Competition::STATUS_CANCELLED, 'completed_at' => now(), 'settings' => array_merge($c->settings ?? [], ['cancel_reason' => $e->getMessage()])]);
                }
            });

        // 4. Concours arrivés à échéance
        Competition::where('status', Competition::STATUS_LIVE)->where('type', Competition::TYPE_CONTEST)
            ->where('ends_at', '<=', now())->limit(100)->get()
            ->each(fn ($c) => $this->safely(fn () => $competitions->complete($c)));

        // 5. Défis arrivés à échéance
        Competition::whereIn('status', [Competition::STATUS_OPEN, Competition::STATUS_LIVE])->where('type', Competition::TYPE_CHALLENGE)
            ->where('ends_at', '<=', now())->limit(200)->get()
            ->each(fn ($c) => $this->safely(fn () => $competitions->completeChallengeIfDone($c)));

        // 6. Duels ciblés jamais acceptés
        Competition::where('status', Competition::STATUS_OPEN)->where('type', Competition::TYPE_DUEL)
            ->where('registration_ends_at', '<=', now())->limit(200)->get()
            ->each(function (Competition $c) {
                $c->update(['status' => Competition::STATUS_CANCELLED, 'completed_at' => now()]);
                CompetitionInvitation::where('competition_id', $c->id)->where('status', 'pending')->update(['status' => 'expired']);
            });

        // 7. Décroissance du score tendance (environ -10 % par heure)
        if (now()->minute === 0) {
            CompetitionMatch::where('hot_score', '>', 0)->update(['hot_score' => \DB::raw('FLOOR(hot_score * 0.9)')]);
            Competition::where('hot_score', '>', 0)->update(['hot_score' => \DB::raw('FLOOR(hot_score * 0.9)')]);
        }

        return self::SUCCESS;
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('competitions:tick '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->error($e->getMessage());
        }
    }
    /**
     * Prévient l'organisateur (cloche + push, et SMS si un modèle est réglé) quand sa compétition
     * se termine bientôt. Le délai et le texte viennent de la configuration, pas du code.
     */
    private function remindCompetitions(): void
    {
        $hours = max(1, (int) config('competition.durations.competition_reminder_hours', 2));
        $sms = filled(config('messaging.emess.reminder_template'));

        Competition::query()
            ->whereIn('type', [Competition::TYPE_CHALLENGE, Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT])
            ->whereNotIn('status', [Competition::STATUS_COMPLETED, Competition::STATUS_CANCELLED])
            ->whereBetween('ends_at', [now(), now()->addHours($hours)])
            ->limit(200)->get()
            ->each(function (Competition $c) use ($hours, $sms) {
                // Une seule relance par compétition (pas de colonne à ajouter : on passe par le cache)
                if (! \Illuminate\Support\Facades\Cache::add('comp-ending-'.$c->id, 1, now()->addDays(2))) {
                    return;
                }
                $title = '« '.$c->title.' » se termine dans '.$hours.' h';
                $body = 'Dernière chance : partage le lien et relance les votes.';

                $org = $c->organizer;
                $ids = $org instanceof \App\Models\User
                    ? [$org->id]
                    : ($org ? $org->managers()->pluck('users.id')->all() : []);

                if ($ids) {
                    app(\App\Services\Social\NotificationService::class)
                        ->send($ids, 'competition.ending_soon', $title, $body, $c->url(), $org);
                }

                if ($sms && $ids) {
                    $context = [
                        'app_name' => (string) config('messaging.app_name', 'Sosa'),
                        'title' => mb_substr((string) $c->title, 0, 40),
                        'heures' => $hours,
                        'lien' => mb_substr($c->url(), 0, 64),
                    ];
                    $text = \App\Services\Messaging\SmsTemplates::render('reminder', $context) ?? $title;
                    foreach (\App\Models\User::whereIn('id', $ids)->get() as $user) {
                        app(\App\Services\Messaging\Messenger::class)
                            ->send($user->phone_code.$user->phone_number, $text, 'reminder', $context);
                    }
                }
            });
    }
}
