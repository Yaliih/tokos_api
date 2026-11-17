<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Badge;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionInvitation;
use App\Models\CompetitionMatch;
use App\Models\CompetitionVote;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\Space;
use App\Models\User;
use App\Services\Competition\VoteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Mise en forme commune des données pour l'API (JSON) et le site web (Blade).
 * Un seul endroit décide de ce qu'on montre, y compris ce que le spectateur peut faire.
 */
class Presenter
{
    // ═══════════════════════ Compétitions ═══════════════════════

    public static function competition(Competition $c, ?User $viewer = null, bool $detail = false): array
    {
        $c->loadMissing(['organizer', 'creator', 'winnerEntry.participant', 'post']);

        $data = [
            'id' => $c->id,
            'slug' => $c->slug,
            'url' => $c->url(),
            'type' => $c->type,
            'type_label' => $c->typeLabel(),
            'format' => $c->format,
            'title' => $c->title,
            'description' => $c->description,
            'category' => $c->category,
            'category_label' => $c->categoryLabel(),
            'cover_url' => $c->coverUrl(),
            'vote_cost' => \App\Support\Features::on('paid_votes') ? (int) $c->vote_cost : 0,
            'cover_type' => $c->cover ? ($c->cover_type ?: (preg_match('/\.(mp4|mov|webm|3gp)$/i', (string) $c->cover) ? 'video' : 'image')) : null,
            'views_count' => (int) $c->views_count,
            'liked' => $viewer && $c->post ? $c->post->likes()->where('user_id', $viewer->id)->exists() : false,
            'show_duels_in_feed' => (bool) ($c->settings['show_duels_in_feed'] ?? true),
            // Format imposé (y compris celui déduit de la performance du créateur) : seul ce format est proposé
            'media_types' => $c->entryFormat() ? [$c->entryFormat()] : ($c->media_types ?: config('competition.media_types')),
            'prize' => \App\Support\Features::on('prizes') ? $c->prize : null,
            // Récompenses par place ; les coins annoncés sont déjà bloqués chez Sosa
            'prize_tiers' => \App\Support\Features::on('prizes') ? collect($c->prize_tiers ?? [])->map(fn ($t) => [
                'place' => \App\Services\Competition\PrizeService::placeLabel((int) $t['from'], (int) $t['to']),
                'from' => (int) $t['from'], 'to' => (int) $t['to'], 'text' => $t['text'] ?? null,
                'coins' => \App\Support\Features::on('coins') ? (int) ($t['coins'] ?? 0) : 0,
            ])->values()->all() : [],
            'prize_coins_locked' => \App\Support\Features::on('coins') ? (int) $c->prize_escrow : 0,
            'organizer' => Participant::present($c->organizer),
            'participation_mode' => $c->participation_mode,
            'participation_label' => match ($c->participation_mode) {
                'space' => 'Espaces contre Espaces',
                'mixed' => 'Utilisateurs et Espaces',
                default => 'Individuel',
            },
            'access' => $c->access,
            'status' => $c->status,
            'status_label' => $c->statusLabel(),
            // Pour tous les boutons « Participer / S'inscrire / Défier » : rien si c'est fini ou fermé
            'is_over' => $c->isOver(),
            'accepts_entries' => $c->acceptsEntries(),
            'entries_count' => (int) $c->entries_count,
            'max_entries' => $c->max_entries,
            'application' => $c->isApplication(),
            'entry_format' => $c->entryFormat(),
            'entry_format_label' => $c->entryFormat() ? Competition::FORMAT_LABELS[$c->entryFormat()] : null,
            'matches_count' => (int) $c->matches_count,
            'votes_count' => (int) $c->votes_count,
            'registration_ends_at' => self::date($c->registration_ends_at),
            'starts_at' => self::date($c->starts_at),
            'ends_at' => self::date($c->ends_at),
            // Le moment où c'est vraiment fini (pour « Fini il y a… ») : la date de fin prévue peut être plus tard
            'ended_at' => self::date(self::endedAt($c)),
            // Le temps à afficher partout (site et app) : « reste 2 j 4 h » ou « Terminé il y a 3 h »
            'time' => self::timing($c),
            'seconds_left' => $c->ends_at && $c->ends_at->isFuture() ? (int) now()->diffInSeconds($c->ends_at) : null,
            'current_round' => (int) $c->current_round,
            'total_rounds' => $c->type === Competition::TYPE_TOURNAMENT ? $c->totalRounds() : null,
            'round_name' => $c->type === Competition::TYPE_TOURNAMENT && $c->current_round > 0
                ? Competition::roundName($c->current_round, $c->totalRounds()) : null,
            'winner' => $c->winnerEntry ? self::entry($c->winnerEntry, $c) : null,
            'post_id' => $c->post_id,
            'likes_count' => $c->post ? $c->post->likes()->count() : 0,
            'comments_count' => $c->post ? $c->post->allComments()->count() : 0,
            'hot_score' => (int) $c->hot_score,
            'created_at' => self::date($c->created_at),
            'edited_at' => self::date($c->edited_at),
            // La remise du prix filmée : « Prix remis » sur la carte, la vidéo sur la page
            'prize_delivered' => $c->prize_proof_at !== null,
        ];
        // Personnes et Espaces cités avec @ dans la description
        if (str_contains((string) $c->description, '@') && \App\Support\Features::on('mentions')) {
            $data['mentions'] = \App\Support\Mentions::map($c->description) ?: null;
        }

        // Aperçu pour les cartes du fil : le duel en cours ou les premiers participants
        $preview = $c->matches()->with(['entryA.participant', 'entryB.participant'])
            ->orderByRaw("CASE WHEN status = 'voting' THEN 0 ELSE 1 END")->orderByDesc('hot_score')->orderByDesc('id')->first();
        $data['featured_match'] = $preview ? self::match($preview, $viewer, false, $c) : null;
        // Défi ou duel : la performance du créateur sert de vitrine (pas de couverture)
        if (in_array($c->type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true) && $c->format !== 'versus' && $c->organizer) {
            $owner = $c->entries()->with('participant')->where('participant_type', $c->organizer::class)
                ->where('participant_id', $c->organizer->getKey())->first();
            $data['owner_entry'] = $owner ? self::entry($owner, $c) : null;
        }
        $data['blurb'] = self::blurb($c, $owner ?? null);
        if (! $preview || $c->type === Competition::TYPE_CHALLENGE || $c->type === Competition::TYPE_CONTEST) {
            $data['top_entries'] = $c->entries()->with('participant')->whereIn('status', ['active', 'winner'])
                ->orderByRaw('CASE WHEN `rank` IS NULL THEN 1 ELSE 0 END')->orderBy('rank')
                ->orderByDesc('votes_count')->orderBy('id')->limit(3)->get()
                ->map(fn ($e) => self::entry($e, $c))->all();
        }

        if ($detail) {
            $data += [
                'rules' => $c->rules,
                'creator' => Participant::present($c->creator),
                'requires_approval' => (bool) $c->requires_approval,
                'min_entries' => (int) $c->min_entries,
                'vote_audience' => $c->vote_audience,
                'member_vote_policy' => $c->member_vote_policy,
                'blind_votes' => false,
                'jury_weight' => (int) $c->jury_weight,
                'geo_city' => \App\Support\Geo::zone($c), 'geo_scope' => $c->geo_scope,
                'votes_per_user' => (int) $c->votes_per_user,
                'winners_count' => (int) $c->winners_count,
                'match_duration_hours' => (int) $c->match_duration_hours,
                'jurors' => $c->jurors()->get()->map(fn ($u) => Participant::present($u))->all(),
                'completed_at' => self::date($c->completed_at),
                'prize_proof' => \App\Services\Competition\PrizeProof::present($c),
                'can_publish_prize' => $c->hasPrize() && \App\Services\Competition\PrizeProof::canPublish($viewer, $c),
                'has_prize' => $c->hasPrize(),
                'rules_summary' => self::rulesSummary($c),
            ];
        }

        $data['viewer'] = self::competitionViewer($c, $viewer);

        return $data;
    }

    /** Phrases claires qui résument les règles (affichées sous le titre). */
    public static function rulesSummary(Competition $c): array
    {
        $lines = [];
        $lines[] = match ($c->type) {
            Competition::TYPE_CHALLENGE => 'Chaque challenger affronte '.Participant::name($c->organizer).' en duel, le public vote.',
            Competition::TYPE_DUEL => $c->format === 'versus' ? 'Deux propositions, un seul gagnant : à toi de voter.' : 'Un duel en face à face, le public décide.',
            Competition::TYPE_CONTEST => 'Les candidats sont classés selon les votes'.($c->jury_weight > 0 ? ' et la note du jury' : '').'.',
            Competition::TYPE_TOURNAMENT => 'Élimination directe : chaque duel gagné te qualifie pour le tour suivant.',
            default => '',
        };
        $lines[] = $c->format === 'versus' ? null : match ($c->participation_mode) {
            'space' => 'Compétition entre Espaces : chaque Espace envoie un représentant.',
            'mixed' => 'Ouvert aux utilisateurs et aux Espaces.',
            default => 'Participation individuelle.',
        };
        if ($c->access === 'members') {
            $lines[] = 'Réservé aux membres de '.Participant::name($c->organizer).'.';
        } elseif ($c->access === 'invited') {
            $lines[] = 'Sur invitation uniquement.';
        }
        if ($c->type === Competition::TYPE_CHALLENGE) {
            $lines[] = 'Tous les duels se terminent à la fin du défi : relève-le tôt pour avoir plus de temps.';
        } elseif ($c->type === Competition::TYPE_DUEL) {
            $lines[] = 'Le duel dure '.$c->match_duration_hours.' h.';
        } elseif ($c->type === Competition::TYPE_TOURNAMENT) {
            $lines[] = 'Chaque tour dure '.$c->match_duration_hours.' h.';
        }
        if ($c->type === Competition::TYPE_CONTEST && $c->votes_per_user > 1 && ! $c->vote_cost) {
            $lines[] = 'Chaque votant peut soutenir '.$c->votes_per_user.' candidats.';
        }
        $lines[] = match ($c->vote_audience) {
            'followers' => 'Seuls les abonnés de l\'organisateur votent.',
            'members' => 'Seuls les membres de l\'Espace votent.',
            'jury' => 'Seul le jury vote.',
            default => 'Tout le monde peut voter.',
        };
        if ($c->participation_mode !== 'individual' && $c->member_vote_policy !== 'allowed') {
            $lines[] = $c->member_vote_policy === 'excluded'
                ? 'Les membres des Espaces en compétition ne votent pas (vote neutre).'
                : 'Les votes des membres des Espaces en compétition comptent à moitié.';
        }
        if (($zone = \App\Support\Geo::zone($c)) && \App\Support\Features::on('geo_limits')) {
            $lines[] = match ($c->geo_scope ?: 'both') {
                'enter' => 'Seules les personnes à '.$zone.' participent (position vérifiée). Tout le monde vote.',
                'vote' => 'Seules les personnes à '.$zone.' votent (position vérifiée).',
                default => 'Réservé à '.$zone.' pour participer et voter (position vérifiée).',
            };
        }
        if ($c->jury_weight > 0 && in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
            $lines[] = $c->vote_audience === 'jury' ? null
                : 'Le jury compte pour '.$c->jury_weight.' %, le public pour '.(100 - $c->jury_weight).' %.';
        }
        if ($c->entryFormat() && ! $c->isApplication()) {
            $label = mb_strtolower(Competition::FORMAT_LABELS[$c->entryFormat()]);
            $lines[] = $c->type === Competition::TYPE_CONTEST
                ? 'Participation en '.$label.' uniquement.'
                : 'Format '.$label.' contre '.$label.' : on répond comme le créateur.';
        }
        if ($c->isApplication()) {
            $lines[] = 'Sur candidature : photo, présentation et courte vidéo facultative, validées par l\'organisateur.';
        }
        if ((int) $c->vote_cost > 0) {
            $lines[] = 'Votes payants : '.$c->vote_cost.' coin'.($c->vote_cost > 1 ? 's' : '').' par vote. Tu peux voter plusieurs fois, '.($c->type === Competition::TYPE_CONTEST ? 'pour un seul candidat.' : 'pour un seul camp par duel.');
        }
        $lines[] = 'Les scores sont visibles en direct.';

        return array_values(array_filter($lines));
    }

    /** La prestation envoyée pour ce tour remplace celle de l'inscription. */
    private static function roundEntry(CompetitionMatch $m, string $side, array $entry): array
    {
        if ($m->{$side.'_submitted_at'} && $m->status !== 'preparing') {
            $entry['content'] = $m->{$side.'_content'};
            $entry['media'] = MediaUrl::list($m->{$side.'_media'} ?? []);
        }

        return $entry;
    }

    public static function competitionViewer(Competition $c, ?User $viewer): array
    {
        if (! $viewer) {
            return ['can_manage' => false, 'can_enter' => $c->acceptsEntries(), 'enter_as' => [], 'my_entry_ids' => [], 'is_juror' => false, 'invitation' => null, 'votes_left' => null, 'login_required' => true, 'can_propose' => false];
        }

        $spaceIds = $viewer->publishableSpaceIds();
        $myEntries = CompetitionEntry::where('competition_id', $c->id)
            ->whereNotIn('status', ['withdrawn'])
            ->where(function ($q) use ($viewer, $spaceIds) {
                $q->where(fn ($x) => $x->where('participant_type', User::class)->where('participant_id', $viewer->id))
                    ->orWhere(fn ($x) => $x->where('participant_type', Space::class)->whereIn('participant_id', $spaceIds ?: [0]))
                    ->orWhere('submitted_by', $viewer->id);
            })->get();

        // Sous quelles identités le spectateur peut-il participer ?
        $enterAs = [];
        if ($c->acceptsEntries()) {
            $isOrganizer = fn ($m) => $c->organizer_type === $m::class && (int) $c->organizer_id === (int) $m->getKey();
            $taken = $myEntries->map(fn ($e) => $e->participant_type.':'.$e->participant_id)->all();
            $isDuelLike = in_array($c->type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true);

            if ($c->participation_mode !== 'space' && ! in_array(User::class.':'.$viewer->id, $taken, true) && ! ($isDuelLike && $isOrganizer($viewer))) {
                $enterAs[] = Participant::present($viewer);
            }
            if ($c->participation_mode !== 'individual' && $spaceIds) {
                foreach (Space::whereIn('id', $spaceIds)->get() as $space) {
                    if (! in_array(Space::class.':'.$space->id, $taken, true) && ! ($isDuelLike && $isOrganizer($space))) {
                        $enterAs[] = Participant::present($space);
                    }
                }
            }
            if ($c->access === 'invited') {
                $invited = CompetitionInvitation::where('competition_id', $c->id)->whereIn('status', ['pending', 'accepted'])->get()
                    ->map(fn ($i) => ($i->invitee_type === Space::class ? 'space' : 'user').':'.$i->invitee_id)->all();
                $enterAs = array_values(array_filter($enterAs, fn ($p) => in_array($p['type'].':'.$p['id'], $invited, true)));
            }
            if ($c->access === 'members' && ($space = $c->organizerSpace()) && ! $space->hasAffiliate($viewer)) {
                $enterAs = [];
            }
            // L'organisateur et le jury d'un concours ou d'un tournoi ne participent pas
            if (in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true) && ($c->canManage($viewer) || $c->isJuror($viewer))) {
                $enterAs = [];
            }
        }

        $invitation = CompetitionInvitation::where('competition_id', $c->id)->where('status', 'pending')
            ->where(function ($q) use ($viewer, $spaceIds) {
                $q->where(fn ($x) => $x->where('invitee_type', User::class)->where('invitee_id', $viewer->id))
                    ->orWhere(fn ($x) => $x->where('invitee_type', Space::class)->whereIn('invitee_id', $spaceIds ?: [0]));
            })->first();

        $votesLeft = null;
        if ($c->type === Competition::TYPE_CONTEST) {
            $used = CompetitionVote::where('competition_id', $c->id)->whereNull('match_id')->where('user_id', $viewer->id)->count();
            $votesLeft = max(0, $c->votes_per_user - $used);
        }

        $member = ($space = $c->organizerSpace()) ? $space->hasAffiliate($viewer) : null;
        // Réservé à une ville : position à vérifier, ou mauvaise ville (l'organisateur n'est pas concerné)
        $isCreator = (int) $c->created_by === (int) $viewer->id;
        $geo = fn ($action) => ! $isCreator && \App\Support\Features::on('geo_limits') && \App\Support\Geo::limits($c, $action);
        $pos = \App\Support\Geo::position();
        $fresh = $pos !== null;
        $wrongCity = $fresh && ! \App\Support\Geo::inZone($pos, $c, $viewer);
        if ($geo('enter') && $wrongCity) {
            $enterAs = [];
        }

        return [
            'geo_locate' => ($geo('enter') || $geo('vote')) && ! $fresh,
            'geo_wrong_city' => ($geo('enter') || $geo('vote')) && $wrongCity ? ($pos['city'] ?: 'un autre endroit') : null,
            'can_manage' => $c->canManage($viewer),
            'can_enter' => ! empty($enterAs),
            'enter_as' => $enterAs,
            'my_entry_ids' => $myEntries->pluck('id')->all(),
            'my_entry_status' => $myEntries->first()?->status,
            'is_juror' => $c->isJuror($viewer),
            'invitation' => $invitation ? ['id' => $invitation->id, 'message' => $invitation->message] : null,
            'votes_left' => $votesLeft,
            'is_member_of_organizer' => $member,
            'can_propose' => $c->participation_mode !== 'individual' && $c->acceptsEntries() && ! empty($viewer->allSpaceIds()),
            'login_required' => false,
        ];
    }

    /**
     * Le texte qui accompagne la publication dans le fil, sous le titre (replié avec « Lire plus »).
     * La description si elle existe ; sinon, pour un défi ou un duel avec un média, le mot du créateur.
     * Une performance en texte s'affiche déjà dans sa carte : pas de doublon.
     */
    public static function blurb(Competition $c, ?CompetitionEntry $owner = null): ?string
    {
        $t = trim((string) $c->description);
        if ($t === '' && $owner && ! empty($owner->media)) {
            $t = trim((string) $owner->content);
        }
        if ($t === '' || mb_strtolower($t) === mb_strtolower(trim((string) $c->title))) {
            return null;
        }

        return $t;
    }

    public static function entry(CompetitionEntry $e, ?Competition $c = null, ?User $viewer = null): array
    {
        $c ??= $e->competition;
        $e->loadMissing(['participant', 'submitter']);
        $hideVotes = $c && $c->blind_votes && $c->status !== Competition::STATUS_COMPLETED;

        $data = [
            'id' => $e->id,
            'participant' => Participant::present($e->participant),
            'label' => $e->label,
            'display_name' => $e->displayName(),
            'avatar_url' => $e->participant ? Participant::present($e->participant)['avatar_url'] : null,
            'submitted_by' => $e->participant instanceof Space || ! $e->participant ? Participant::present($e->submitter) : null,
            'content' => $e->content,
            'media' => $e->mediaUrls(),
            'status' => $e->status,
            'seed' => $e->seed,
            'rank' => $e->rank,
            'votes_count' => $hideVotes ? null : (int) $e->votes_count,
            'score' => $hideVotes ? null : round((float) $e->score, 2),
            'wins' => (int) $e->wins,
            'losses' => (int) $e->losses,
            'draws' => (int) $e->draws,
            'created_at' => self::date($e->created_at),
        ];
        if (str_contains((string) $e->content, '@') && \App\Support\Features::on('mentions')) {
            $data['mentions'] = \App\Support\Mentions::map($e->content) ?: null;
        }

        if ($viewer && $c && $c->type === Competition::TYPE_CONTEST) {
            $myQty = CompetitionVote::where('contest_entry_id', $e->id)->where('user_id', $viewer->id)->value('quantity');
            $data['voted_by_me'] = $myQty !== null;
            $data['my_votes'] = (int) $myQty;
        }

        return $data;
    }

    /**
     * Défi : chaque réponse mène à son duel (le plus récent). Une seule requête pour toute la liste.
     * @param iterable<array> $entries
     */
    public static function withDuelLinks(iterable $entries, Competition $c): array
    {
        $entries = collect($entries)->values();
        if (! in_array($c->type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true) || $entries->isEmpty()) {
            return $entries->all();
        }
        $ids = $entries->pluck('id')->all();
        $urls = [];
        CompetitionMatch::where('competition_id', $c->id)->where(fn ($q) => $q->whereIn('entry_a_id', $ids)->orWhereIn('entry_b_id', $ids))
            ->orderBy('id')->get(['id', 'entry_a_id', 'entry_b_id', 'competition_id'])
            ->each(function ($m) use (&$urls) {
                foreach ([$m->entry_a_id, $m->entry_b_id] as $eid) {
                    if ($eid) {
                        $urls[$eid] = $m->url();
                    }
                }
            });
        $owner = [$c->organizer_type === Space::class ? 'space' : 'user', (int) $c->organizer_id];

        return $entries->map(fn ($e) => $e + [
            // La réponse du créateur est dans tous les duels : pas de bouton pour elle
            'match_url' => [($e['participant']['type'] ?? null), (int) ($e['participant']['id'] ?? 0)] === $owner ? null : ($urls[$e['id']] ?? null),
        ])->all();
    }

    // ═══════════════════════ Duels ═══════════════════════

    public static function match(CompetitionMatch $m, ?User $viewer = null, bool $detail = false, ?Competition $c = null): array
    {
        $c ??= $m->competition;
        $m->setRelation('competition', $c);
        $m->loadMissing(['entryA.participant', 'entryB.participant']);

        $completed = $m->status === 'completed';
        $hide = $c->blind_votes && ! $completed;
        $scores = $completed ? ['a' => (float) $m->score_a, 'b' => (float) $m->score_b] : $m->liveScores();

        $myVoteRow = $viewer ? CompetitionVote::where('match_id', $m->id)->where('user_id', $viewer->id)->first(['entry_id', 'quantity']) : null;
        $myVote = $myVoteRow?->entry_id;

        $canVote = false;
        $reason = null;
        if (! $m->isOpenForVotes()) {
            $reason = $completed ? 'Duel terminé' : 'Votes fermés';
        } elseif (! $viewer) {
            $reason = 'Connecte-toi pour voter';
        } else {
            $stake = array_merge($m->entryA?->stakeholderIds() ?? [], $m->entryB?->stakeholderIds() ?? []);
            if (in_array((int) $viewer->id, $stake, true)) {
                $reason = 'Tu participes à ce duel';
            } elseif ($detail) {
                try {
                    if ($c->vote_audience === 'jury' && ! $c->isJuror($viewer)) {
                        throw new \App\Exceptions\CompetitionException('Seul le jury vote');
                    }
                    if (! $c->isJuror($viewer)) {
                        app(VoteService::class)->weightFor($viewer, $c, array_filter([$m->entryA, $m->entryB]), true);
                    }
                    $canVote = true;
                } catch (\App\Exceptions\CompetitionException $e) {
                    $reason = $e->getMessage();
                }
            } else {
                $canVote = true;
            }
        }

        // Le spectateur est-il dans cette compétition ? (« Tu participes au défi lancé par… », bouton Défier)
        $involvement = null;
        if ($viewer) {
            $isOrganizer = ($c->organizer_type === User::class && (int) $c->organizer_id === (int) $viewer->id)
                || ($c->organizer_type === Space::class && in_array((int) $c->organizer_id, array_map('intval', $viewer->manageableSpaceIds()), true));
            $inMatch = in_array((int) $viewer->id, array_merge($m->entryA?->stakeholderIds() ?? [], $m->entryB?->stakeholderIds() ?? []), true);
            $inCompetition = $inMatch || CompetitionEntry::where('competition_id', $c->id)->whereNotIn('status', ['withdrawn'])
                ->where(fn ($q) => $q->where(fn ($x) => $x->where('participant_type', User::class)->where('participant_id', $viewer->id))->orWhere('submitted_by', $viewer->id))
                ->exists();
            $involvement = $isOrganizer ? 'organizer' : ($inCompetition ? 'participant' : null);
        }
        $canDefy = $c->type === Competition::TYPE_CHALLENGE && $c->acceptsEntries() && $involvement === null;

        // Ordre d'affichage aléatoire mais stable par spectateur (évite l'avantage du "premier affiché")
        $swap = $viewer ? (crc32($viewer->id.'-'.$m->id) % 2 === 1) : false;

        $total = max(1, $c->totalRounds());
        $data = [
            'id' => $m->id,
            'url' => $m->url(),
            'competition' => [
                'id' => $c->id, 'slug' => $c->slug, 'title' => $c->title, 'type' => $c->type,
                'type_label' => $c->typeLabel(), 'format' => $c->format, 'url' => $c->url(), 'entry_format' => $c->entryFormat(),
                'category_label' => $c->categoryLabel(), 'blind_votes' => (bool) $c->blind_votes,
                'jury_weight' => (int) $c->jury_weight, 'status' => $c->status, 'vote_cost' => \App\Support\Features::on('paid_votes') ? (int) $c->vote_cost : 0,
                'likes_count' => $c->post ? $c->post->likes()->count() : 0,
                'comments_count' => $c->post ? $c->post->allComments()->count() : 0,
                'liked' => $viewer && $c->post ? $c->post->likes()->where('user_id', $viewer->id)->exists() : false,
                'organizer' => Participant::present($c->organizer),
                'description' => $c->description,
                'blurb' => self::blurb($c, collect([$m->entryA, $m->entryB])->first(fn ($e) => $e && $c->organizer
                    && $e->participant_type === $c->organizer::class && (int) $e->participant_id === (int) $c->organizer->getKey())),
            ],
            'views_count' => (int) $m->views_count,
            'round' => $m->round,
            'round_name' => $c->type === Competition::TYPE_TOURNAMENT ? Competition::roundName($m->round, $total) : null,
            'position' => $m->position,
            'status' => $m->status,
            'starts_at' => self::date($m->starts_at),
            'ends_at' => self::date($m->ends_at),
            'ended_at' => self::date($m->status === 'completed' ? ($m->ends_at && $m->ends_at->isPast() && (! $m->updated_at || $m->ends_at->lt($m->updated_at)) ? $m->ends_at : $m->updated_at) : null),
            'time' => self::matchTiming($m),
            'seconds_left' => $m->isOpenForVotes() && $m->ends_at ? (int) now()->diffInSeconds($m->ends_at) : 0,
            'entry_a' => $m->entryA ? self::roundEntry($m, 'a', self::entry($m->entryA, $c)) : null,
            'entry_b' => $m->entryB ? self::roundEntry($m, 'b', self::entry($m->entryB, $c)) : null,
            // Tournoi, 2e tour et suivants : chacun envoie sa nouvelle prestation avant l'heure limite
            'preparing' => $m->status === 'preparing' ? [
                'submit_by' => self::date($m->submit_by),
                'a_ready' => (bool) $m->a_submitted_at, 'b_ready' => (bool) $m->b_submitted_at,
                'my_side' => \App\Services\Competition\MatchService::sideOf($m, $viewer),
            ] : null,
            'forfeit' => $m->forfeit,
            'votes_a' => $hide ? null : (int) $m->votes_a,
            'votes_b' => $hide ? null : (int) $m->votes_b,
            'total_votes' => $m->totalVotes(),
            'score_a' => $hide ? null : $scores['a'],
            'score_b' => $hide ? null : $scores['b'],
            'is_close' => ! $hide && abs($scores['a'] - $scores['b']) < 10 && $m->totalVotes() >= 10,
            'winner_entry_id' => $m->winner_entry_id,
            'is_draw' => (bool) $m->is_draw,
            'is_bye' => (bool) $m->is_bye,
            'display_order' => $swap ? ['b', 'a'] : ['a', 'b'],
            'jury' => $c->jury_weight > 0 ? ['weight' => (int) $c->jury_weight, 'a' => (int) $m->jury_a, 'b' => (int) $m->jury_b] : null,
            'viewer' => [
                'is_juror' => $viewer ? $c->isJuror($viewer) : false,
                'my_vote_entry_id' => $myVote,
                'my_votes' => (int) ($myVoteRow?->quantity ?? 0),
                'can_vote' => $canVote,
                'reason' => $reason,
                'involvement' => $involvement,
                'can_defy' => $canDefy,
                'can_rematch' => \App\Support\Features::on('rematch') && $viewer && $completed && $c->type !== Competition::TYPE_TOURNAMENT
                    && ! $m->is_bye && $m->entryA?->participant && $m->entryB?->participant
                    && in_array((int) $viewer->id, array_merge($m->entryA->stakeholderIds(), $m->entryB->stakeholderIds()), true),
            ],
        ];

        if ($detail) {
            $data['reactions'] = self::reactions($m, $viewer);
        }

        return $data;
    }

    public static function reactions($reactable, ?User $viewer): array
    {
        $counts = Reaction::where('reactable_type', $reactable::class)->where('reactable_id', $reactable->getKey())
            ->selectRaw('emoji, count(*) as total')->groupBy('emoji')->pluck('total', 'emoji');
        $mine = $viewer ? Reaction::where('reactable_type', $reactable::class)->where('reactable_id', $reactable->getKey())->where('user_id', $viewer->id)->value('emoji') : null;

        return [
            'available' => Reaction::EMOJIS,
            'counts' => collect(Reaction::EMOJIS)->mapWithKeys(fn ($e) => [$e => (int) ($counts[$e] ?? 0)])->all(),
            'mine' => $mine,
        ];
    }

    // ═══════════════════════ Fil d'activité ═══════════════════════

    /** Regroupe les activités similaires ("A, B et 3 autres participent à X") puis les met en forme. */
    public static function activities(Collection $items, ?User $viewer = null): array
    {
        $groupable = ['entry.submitted', 'space.joined', 'user.followed', 'challenge.accepted'];
        $groups = [];
        foreach ($items as $a) {
            $groupKey = $a->verb === 'challenge.accepted'
                ? $a->verb.'|'.$a->target_type.'|'.$a->target_id
                : $a->verb.'|'.$a->subject_type.'|'.$a->subject_id;
            $key = in_array($a->verb, $groupable, true) ? $groupKey.'|'.$a->created_at?->format('Y-m-d') : 'single|'.$a->id;
            $groups[$key][] = $a;
        }

        return collect($groups)->map(function ($group) use ($viewer) {
            $first = $group[0];
            $actors = collect($group)->map(fn ($a) => $a->actor)->filter()->unique(fn ($m) => $m::class.$m->getKey())->values();

            return self::activity($first, $viewer, $actors);
        })->filter()->values()->all();
    }

    public static function activity(Activity $a, ?User $viewer = null, ?Collection $actors = null): ?array
    {
        $actor = $a->actor;
        if (! $actor) {
            return null;
        }
        $actors ??= collect([$actor]);
        $d = $a->data ?? [];
        $comp = $d['competition'] ?? null;
        $compLink = fn () => $comp ? ['type' => 'link', 'text' => '« '.$comp['title'].' »', 'url' => $comp['url']] : ['type' => 'text', 'text' => 'une compétition'];

        $who = self::actorsSegments($actors);
        $plural = $actors->count() > 1;
        $subjectP = Participant::present($a->subject instanceof User || $a->subject instanceof Space ? $a->subject : null);

        [$icon, $segments, $url, $preview] = match ($a->verb) {
            'competition.created' => ['', [...$who, self::t(' '.self::createdVerb($comp)), $compLink()], $comp['url'] ?? null, self::previewCompetition($a->subject, $viewer, $comp)],
            'duel.invited' => ['', [...$who, self::t(' a défié '), self::p($subjectP, $d['opponent_name'] ?? 'quelqu\'un'), self::t(' : '), $compLink()], $comp['url'] ?? null, self::previewCompetition($a->target, $viewer, $comp)],
            'challenge.accepted' => ['', [...$who, self::t($plural ? ' relèvent le défi ' : ' relève le défi '), $compLink(), self::t(' de '.($d['owner_name'] ?? ''))], $a->subject instanceof CompetitionMatch ? $a->subject->url() : ($comp['url'] ?? null), $a->subject instanceof CompetitionMatch ? ['kind' => 'match', 'match' => self::match($a->subject, $viewer)] : null],
            'entry.submitted' => ['', [...$who, self::t($plural ? ' participent à ' : ' participe à '), $compLink()], $comp['url'] ?? null, $plural ? null : self::previewCompetition($a->subject, $viewer, $comp)],
            'match.completed' => ($d['is_draw'] ?? false)
                ? ['', [self::b($d['a_name'] ?? ''), self::t(' et '), self::b($d['b_name'] ?? ''), self::t(' font match nul dans '), $compLink()], $a->subject instanceof CompetitionMatch ? $a->subject->url() : null, self::previewMatch($a->subject, $viewer)]
                : ['', [self::b($d['winner_name'] ?? ''), self::t(' a battu '), self::b($d['loser_name'] ?? ''), self::t(' ('.self::pct($d['winner_score'] ?? 0).' – '.self::pct($d['loser_score'] ?? 0).') dans '), $compLink()], $a->subject instanceof CompetitionMatch ? $a->subject->url() : null, self::previewMatch($a->subject, $viewer)],
            'match.milestone' => ['', [self::t('Le duel '), self::b(($d['a_name'] ?? '').' vs '.($d['b_name'] ?? '')), self::t(' dépasse '.$d['votes'].' votes')], $a->subject instanceof CompetitionMatch ? $a->subject->url() : null, self::previewMatch($a->subject, $viewer)],
            'tournament.started' => ['', [self::t('Le tournoi '), $compLink(), self::t(' commence avec '.($d['entries'] ?? '?').' participants')], $comp['url'] ?? null, ! empty($d['bracket']['pairs']) ? ['kind' => 'bracket', 'bracket' => $d['bracket'] + ['round_name' => $d['round_name'] ?? null], 'competition' => $comp] : self::previewCompetition($a->subject, $viewer, $comp)],
            'tournament.round' => ['', [$compLink(), self::t(' : place aux '.($d['round_name'] ?? 'tour suivant').' ('.($d['remaining'] ?? '?').' encore en lice)')], $comp['url'] ?? null, ! empty($d['bracket']['pairs']) ? ['kind' => 'bracket', 'bracket' => $d['bracket'] + ['round_name' => $d['round_name'] ?? null], 'competition' => $comp] : self::previewCompetition($a->subject, $viewer, $comp)],
            'contest.standings' => ['', [self::t('Classement de '), $compLink()], $comp['url'] ?? null, ['kind' => 'standings', 'standings' => $d['standings'] ?? ['rows' => [], 'total' => 0, 'final' => false], 'competition' => $comp]],
            'contest.voting' => ['', [self::t('Les votes sont ouverts pour '), $compLink(), self::t(' ('.($d['entries'] ?? '?').' candidats)')], $comp['url'] ?? null, self::previewCompetition($a->subject, $viewer, $comp)],
            'competition.completed' => self::completedLine($a, $who, $compLink(), $d, $viewer, $comp),
            'space.joined' => ['', [...$who, self::t($plural ? ' ont rejoint l\'espace ' : ' a rejoint l\'espace '), self::p($subjectP, $d['space_name'] ?? 'un espace')], $subjectP['url'] ?? null, $subjectP ? ['kind' => 'space', 'space' => $subjectP + ['members_count' => $a->subject?->members_count]] : null],
            'space.created' => ['', [...$who, self::t(' a créé l\'espace '), self::p($subjectP, $d['space_name'] ?? 'un espace')], $subjectP['url'] ?? null, $subjectP ? ['kind' => 'space', 'space' => $subjectP] : null],
            'user.followed' => ['', [...$who, self::t($plural ? ' suivent maintenant ' : ' suit maintenant '), self::p($subjectP, $d['user_name'] ?? '')], $subjectP['url'] ?? null, null],
            'badge.earned' => ['🏅', [...$who, self::t(' a obtenu le badge '), self::b($d['name'] ?? '')], Participant::present($actor)['url'] ?? null, ['kind' => 'badge', 'badge' => ['code' => $d['code'] ?? null, 'name' => $d['name'] ?? null, 'emoji' => $d['emoji'] ?? null, 'description' => config('badges.'.($d['code'] ?? '').'.description')]]],
            'streak' => ['', [...$who, self::t(' enchaîne '), self::b(($d['streak'] ?? '?').' victoires'), self::t(' d\'affilée')], Participant::present($actor)['url'] ?? null, null],
            'rank.top10' => ['', [...$who, self::t(' entre dans le '), self::b('top 10'), self::t(' du classement (#'.($d['rank'] ?? '?').')')], '/classements', null],
            // Publications automatiques (Pulse) : la plateforme raconte ce qui se passe
            'contest.leader' => ['', [...$who, self::t(($d['again'] ?? false) ? ' repasse premier au concours ' : ' prend la tête du concours '), $compLink(), self::t(' ('.($d['votes'] ?? 0).' votes)')], $comp['url'] ?? null, ! empty($d['standings']['rows']) ? ['kind' => 'standings', 'standings' => $d['standings'], 'competition' => $comp] : self::previewCompetition($a->subject, $viewer, $comp)],
            'contest.surge' => ['', [...$who, self::t(' cumule le plus de voix aujourd\'hui dans '), $compLink(), self::t(' (+'.($d['votes'] ?? 0).' votes en 24 h'.(! empty($d['rank']) ? ', '.\App\Services\Social\Pulse::place((int) $d['rank']).' au classement' : '').')')], $comp['url'] ?? null, self::previewCompetition($a->subject, $viewer, $comp)],
            'competition.entries' => ['', [$compLink(), self::t(' atteint '), self::b(($d['entries'] ?? '?').' participants')], $comp['url'] ?? null, self::previewCompetition($a->subject, $viewer, $comp)],
            'competition.last_call' => ['', [self::t('Dernières heures pour voter dans '), $compLink(), self::t(' : il reste moins de '.($d['hours'] ?? 6).' h')], $comp['url'] ?? null, self::previewCompetition($a->subject, $viewer, $comp)],
            'match.tight' => ['', [self::t('Duel serré : '), self::b(($d['a_name'] ?? '').' et '.($d['b_name'] ?? '')), self::t(' à '.($d['gap'] ?? 0).' voix d\'écart dans '), $compLink(), self::t('. Ton vote peut tout changer')], $a->subject instanceof CompetitionMatch ? $a->subject->url() : ($comp['url'] ?? null), self::previewMatch($a->subject, $viewer)],
            'prize.delivered' => ['', [self::t('Remise du prix : '), self::b($d['winner_name'] ?? 'le gagnant'), self::t(' reçoit son prix de '), $compLink()], isset($comp['url']) ? $comp['url'].'#remise' : null, ['kind' => 'post', 'post' => ['id' => null, 'slug' => null, 'content' => (string) ($d['text'] ?? ''), 'parts' => \App\Support\Mentions::parts((string) ($d['text'] ?? '')), 'media' => MediaUrl::list(is_array($d['media'] ?? null) ? $d['media'] : [])], 'competition' => $comp]],
            'post.created' => ['', [...$who, self::t(' a publié')], $a->subject instanceof Post ? '/p/'.$a->subject->slug : null, $a->subject instanceof Post ? ['kind' => 'post', 'post' => ['id' => $a->subject->id, 'slug' => $a->subject->slug, 'content' => \Illuminate\Support\Str::limit((string) $a->subject->content, 220), 'media' => MediaUrl::list(is_array($a->subject->media) ? $a->subject->media : [])]] : null],
            default => ['•', [...$who, self::t(' '.$a->verb)], null, null],
        };

        return [
            'id' => $a->id,
            'verb' => $a->verb,
            'icon' => $icon,
            'segments' => array_values(array_filter($segments)),
            'text' => collect($segments)->filter()->pluck('text')->implode(''),
            'actor' => Participant::present($actor),
            'actors' => $actors->map(fn ($m) => Participant::present($m))->all(),
            // Qui l'on suit, l'Espace rejoint : pour l'afficher à côté de la ligne
            'subject' => $subjectP,
            'url' => $url,
            'preview' => $preview,
            // Le temps, toujours à jour (inscriptions, votes, tour en cours, terminé), même quand l'aperçu est un instantané
            'time' => ($tc = $a->subject instanceof Competition ? $a->subject : ($a->target instanceof Competition ? $a->target : ($a->subject instanceof CompetitionMatch ? $a->subject->competition : null)))
                ? self::timing($tc) : null,
            'importance' => (int) $a->importance,
            'is_suggested' => (bool) ($a->is_suggested ?? false),
            'created_at' => self::date($a->created_at),
            'time_ago' => self::ago($a->created_at),
        ];
    }

    private static function completedLine(Activity $a, array $who, array $compLink, array $d, ?User $viewer, ?array $comp): array
    {
        $type = $comp['type'] ?? null;
        if ($type === Competition::TYPE_CHALLENGE) {
            return ['', [self::t('Le défi '), $compLink, self::t(' est terminé : '.($d['entries'] ?? 0).' participants, '.($d['owner_wins'] ?? 0).' victoire(s) pour l\'organisateur')], $comp['url'] ?? null, self::previewCompetition($a->subject, $viewer, $comp)];
        }
        if ($type === Competition::TYPE_CONTEST && ! empty($d['standings']['rows'])) {
            return ['', [...$who, self::t(' remporte '), $compLink], $comp['url'] ?? null, ['kind' => 'standings', 'standings' => $d['standings'], 'competition' => $comp]];
        }
        if (! empty($d['winner_name'])) {
            return ['', [...$who, self::t(' remporte '), $compLink], $comp['url'] ?? null, self::previewCompetition($a->subject, $viewer, $comp)];
        }

        return ['', [$compLink, self::t(' est terminé')], $comp['url'] ?? null, self::previewCompetition($a->subject, $viewer, $comp)];
    }

    private static function createdVerb(?array $comp): string
    {
        return match ($comp['type'] ?? null) {
            Competition::TYPE_CHALLENGE => 'a lancé le défi ',
            Competition::TYPE_DUEL => ($comp['format'] ?? null) === 'versus' ? 'a lancé le versus ' : 'a lancé le duel ',
            Competition::TYPE_CONTEST => 'organise le concours ',
            Competition::TYPE_TOURNAMENT => 'organise le tournoi ',
            default => 'a créé ',
        };
    }

    private static function previewCompetition($subject, ?User $viewer, ?array $fallback): ?array
    {
        if ($subject instanceof Competition) {
            return ['kind' => 'competition', 'competition' => self::competition($subject, $viewer)];
        }

        return $fallback ? ['kind' => 'competition_summary', 'competition' => $fallback] : null;
    }

    private static function previewMatch($subject, ?User $viewer): ?array
    {
        return $subject instanceof CompetitionMatch ? ['kind' => 'match', 'match' => self::match($subject, $viewer)] : null;
    }

    private static function actorsSegments(Collection $actors): array
    {
        $first = $actors->take(2)->map(fn ($m) => self::p(Participant::present($m), ''))->values()->all();
        if ($actors->count() === 1) {
            return $first;
        }
        if ($actors->count() === 2) {
            return [$first[0], self::t(' et '), $first[1]];
        }

        return [$first[0], self::t(', '), $first[1], self::t(' et '.($actors->count() - 2).' autre'.($actors->count() > 3 ? 's' : ''))];
    }

    private static function t(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    private static function b(string $text): array
    {
        return ['type' => 'strong', 'text' => $text];
    }

    private static function p(?array $p, string $fallback): array
    {
        return $p ? ['type' => 'actor', 'text' => $p['name'], 'url' => $p['url'], 'avatar_url' => $p['avatar_url']] : self::b($fallback);
    }

    private static function pct($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',').' %';
    }

    // ═══════════════════════ Divers ═══════════════════════

    public static function notification($n): array
    {
        $d = $n->data ?? [];

        return [
            'id' => $n->id,
            'type' => $d['type'] ?? $n->type,
            'icon' => $d['icon'] ?? '🔔',
            'title' => $d['title'] ?? '',
            'body' => $d['body'] ?? '',
            'url' => $d['url'] ?? null,
            'actor' => $d['actor'] ?? null,
            'data' => array_diff_key($d, array_flip(['type', 'icon', 'title', 'body', 'url', 'actor'])),
            'read' => $n->read_at !== null,
            'created_at' => self::date($n->created_at),
            'time_ago' => self::ago($n->created_at),
        ];
    }

    public static function badge(Badge $b): array
    {
        $def = $b->definition();

        return ['code' => $b->code, 'name' => $def['name'], 'emoji' => $def['emoji'], 'description' => $def['description'], 'awarded_at' => self::date($b->awarded_at)];
    }

    public static function date($date): ?string
    {
        return $date ? Carbon::parse($date)->toIso8601String() : null;
    }

    /**
     * Le temps d'une compétition, pour tous les types :
     *  state = left (il reste du temps : at = la fin, what = ce qui finit), ended (at = quand), cancelled, none (pas de fin prévue).
     */
    public static function timing(Competition $c): array
    {
        if ($c->status === Competition::STATUS_CANCELLED) {
            return ['state' => 'cancelled', 'label' => 'Annulé'];
        }
        if ($c->timeIsUp()) {
            $e = self::endedAt($c);

            return ['state' => 'ended', 'at' => self::date($e), 'label' => trim('Terminé '.(self::ago($e) ?? ''))];
        }
        if ($c->status === Competition::STATUS_OPEN && $c->type !== Competition::TYPE_CHALLENGE) {
            // Phase d'inscription : jusqu'à la date limite, sinon jusqu'au début prévu
            $until = $c->registration_ends_at?->isFuture() ? $c->registration_ends_at : ($c->starts_at?->isFuture() ? $c->starts_at : null);
            if ($until) {
                return ['state' => 'left', 'at' => self::date($until), 'what' => $c->type === Competition::TYPE_DUEL ? 'Pour accepter' : 'Inscriptions', 'ended_label' => $c->type === Competition::TYPE_DUEL ? 'Invitation expirée' : 'Inscriptions closes'];
            }
            if (in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
                return ['state' => 'info', 'label' => 'Inscriptions ouvertes'];
            }
        }
        if ($c->ends_at?->isFuture()) {
            return ['state' => 'left', 'at' => self::date($c->ends_at), 'what' => match ($c->type) {
                Competition::TYPE_CHALLENGE => 'Défi',
                Competition::TYPE_CONTEST => $c->status === Competition::STATUS_LIVE ? 'Votes' : 'Fin',
                default => 'Fin',
            }];
        }
        // Tournoi en cours, sans date de fin : la fin des votes du tour, ou l'heure limite des prestations
        if ($c->type === Competition::TYPE_TOURNAMENT && $c->status === Competition::STATUS_LIVE) {
            $votes = $c->matches()->where('status', 'voting')->where('ends_at', '>', now())->min('ends_at');
            if ($votes) {
                return ['state' => 'left', 'at' => self::date(Carbon::parse($votes)), 'what' => 'Votes du tour'];
            }
            $prep = $c->matches()->where('status', 'preparing')->where('submit_by', '>', now())->min('submit_by');
            if ($prep) {
                return ['state' => 'left', 'at' => self::date(Carbon::parse($prep)), 'what' => 'Prestations'];
            }

            return ['state' => 'info', 'label' => 'Tournoi en cours'];
        }
        if ($c->status === Competition::STATUS_LIVE) {
            return ['state' => 'info', 'label' => 'Votes en cours'];
        }

        return ['state' => 'none'];
    }

    /** Le temps d'un duel : votes en cours, prestations à envoyer, ou terminé. */
    public static function matchTiming(CompetitionMatch $m): array
    {
        if ($m->status === 'completed') {
            $e = $m->ends_at && $m->ends_at->isPast() && (! $m->updated_at || $m->ends_at->lt($m->updated_at)) ? $m->ends_at : $m->updated_at;

            return ['state' => 'ended', 'at' => self::date($e), 'label' => trim('Terminé '.(self::ago($e) ?? ''))];
        }
        if ($m->status === 'cancelled') {
            return ['state' => 'cancelled', 'label' => 'Annulé'];
        }
        if ($m->ends_at) {
            return $m->ends_at->isFuture()
                ? ['state' => 'left', 'at' => self::date($m->ends_at), 'what' => $m->status === 'preparing' ? 'Prestations' : 'Votes']
                : ['state' => 'ended', 'at' => self::date($m->ends_at), 'label' => trim('Terminé '.(self::ago($m->ends_at) ?? ''))];
        }
        if ($m->status === 'preparing' && $m->submit_by?->isFuture()) {
            return ['state' => 'left', 'at' => self::date($m->submit_by), 'what' => 'Prestations'];
        }

        return ['state' => 'none'];
    }

    /** Quand c'est vraiment fini : la clôture, sinon la fin prévue si elle est passée. Null tant que ça continue. */
    public static function endedAt(Competition $c): ?\Illuminate\Support\Carbon
    {
        $past = $c->ends_at && $c->ends_at->isPast() ? $c->ends_at : null;

        return match ($c->status) {
            Competition::STATUS_COMPLETED => $c->completed_at ?? $past ?? $c->updated_at,
            Competition::STATUS_CANCELLED => $past && $c->updated_at && $past->lt($c->updated_at) ? $past : $c->updated_at,
            default => $past ?? ($c->timeIsUp() ? $c->registration_ends_at : null),
        };
    }

    public static function ago($date): ?string
    {
        if (! $date) {
            return null;
        }
        $date = Carbon::parse($date);
        $s = (int) $date->diffInSeconds(now()); // positif dans le passé
        if ($s < -120) {
            return null; // une date à venir n'est jamais « à l'instant » ni « il y a »
        }
        $s = max(0, $s);

        return match (true) {
            $s < 60 => 'à l\'instant',
            $s < 3600 => 'il y a '.intdiv($s, 60).' min',
            $s < 86400 => 'il y a '.intdiv($s, 3600).' h',
            $s < 7 * 86400 => 'il y a '.intdiv($s, 86400).' j',
            // Toujours « il y a » (jamais une date) : semaines, mois, puis années
            $s < 30 * 86400 => 'il y a '.intdiv($s, 7 * 86400).' sem.',
            $s < 365 * 86400 => 'il y a '.intdiv($s, 30 * 86400).' mois',
            default => 'il y a '.intdiv($s, 365 * 86400).' an'.(intdiv($s, 365 * 86400) > 1 ? 's' : ''),
        };
    }
}
