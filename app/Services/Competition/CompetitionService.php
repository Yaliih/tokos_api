<?php

namespace App\Services\Competition;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionInvitation;
use App\Models\CompetitionJuryScore;
use App\Models\CompetitionMatch;
use App\Models\EntryProposal;
use App\Models\Post;
use App\Models\Space;
use App\Models\User;
use App\Services\MediaUploader;
use App\Services\Social\ActivityService;
use App\Services\Social\BadgeService;
use App\Services\Social\NotificationService;
use App\Services\Social\RatingService;
use App\Support\Participant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Création et gestion des compétitions : défis, duels, concours, tournois.
 * Utilisé à l'identique par l'API mobile et par le site web.
 */
class CompetitionService
{
    public function __construct(
        private MatchService $matches,
        private ActivityService $activities,
        private NotificationService $notifications,
        private RatingService $ratings,
        private BadgeService $badges,
        private MediaUploader $uploader,
    ) {
    }

    // ═════════════════════════════════════════════════════════════
    //  Création
    // ═════════════════════════════════════════════════════════════

    public static function createRules(): array
    {
        $types = config('competition.types');

        return array_merge([
            'type' => ['required', Rule::in($types)],
            'format' => 'nullable|in:versus,challenge,single_elimination',
            // Défi et duel se publient comme un post : le titre est facultatif (tiré du texte, sinon généré)
            'title' => 'required_if:type,contest,tournament|nullable|string|min:3|max:120',
            'description' => 'required_if:type,contest|nullable|string|max:5000',
            'rules' => 'nullable|string|max:5000',
            'category' => ['nullable', Rule::in(array_keys(config('competition.categories')))],
            'media_types' => 'nullable|array',
            // Format imposé des participations : texte, photo, audio ou vidéo (même format que le créateur)
            'entry_format' => ['nullable', Rule::in(['text', 'image', 'audio', 'video'])],
            'media_types.*' => Rule::in(config('competition.media_types')),
            'prize' => 'nullable|string|max:255',
            // Récompenses par place : 1er, 2e, ou une plage (3e à 20e), en texte et/ou en coins
            'prize_tiers' => 'nullable|array|max:20',
            'prize_tiers.*.from' => 'nullable|integer|min:1|max:2048',
            'prize_tiers.*.to' => 'nullable|integer|min:1|max:2048',
            'prize_tiers.*.text' => 'nullable|string|max:120',
            'prize_tiers.*.coins' => 'nullable|integer|min:0|max:10000000',
            // Couverture : une image ou une courte vidéo. Obligatoire pour un concours.
            'cover' => 'required_if:type,contest,tournament|nullable|file|max:'.MediaUploader::maxKb().'|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm,video/3gpp',
            'cover_duration' => 'nullable|numeric',
            // Durée d'un défi (ses duels se terminent tous à la fin du défi) ou d'un duel
            'duration_hours' => 'nullable|integer|min:1|max:720',
            'show_duels_in_feed' => 'nullable|boolean',
            'space' => 'nullable|string|exists:spaces,slug',

            'participation_mode' => 'nullable|in:individual,space,mixed',
            'access' => 'nullable|in:open,members,invited',
            'requires_approval' => 'nullable|boolean',
            'max_entries' => 'nullable|integer|min:2|max:2048',
            'min_entries' => 'nullable|integer|min:2|max:1000',

            'vote_audience' => 'nullable|in:public,followers,members,jury',
            'member_vote_policy' => 'nullable|in:allowed,reduced,excluded',
            'blind_votes' => 'nullable|boolean',
            'jury_weight' => 'nullable|integer|min:0|max:100',
            'votes_per_user' => 'nullable|integer|min:1|max:10',
            'vote_cost' => 'nullable|integer|min:0|max:'.(int) config('coins.max_vote_cost', 1000),
            'winners_count' => 'nullable|integer|min:1|max:10',
            'geo_city' => 'nullable|string|max:80',
            // Zones choisies dans la recherche de lieux (identifiants renvoyés par /lieux), plusieurs possibles
            'geo_place' => 'nullable|array|max:10',
            'geo_place.*' => 'integer|exists:places,id',
            'geo_scope' => 'nullable|in:enter,vote,both',
            'jurors' => 'nullable|array|max:15',
            'jurors.*' => 'string|exists:users,username',

            'registration_ends_at' => 'nullable|date|after:now',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:now',
            'match_duration_hours' => 'nullable|integer|min:'.config('competition.durations.min_match_hours').'|max:'.config('competition.durations.max_match_hours'),

            // Participation du créateur (défi / duel)
            'content' => 'nullable|string|max:5000',

            // Versus : les deux côtés fournis par le créateur
            'side_a_label' => 'required_if:format,versus|nullable|string|max:80',
            'side_a_content' => 'nullable|string|max:2000',
            'side_b_label' => 'required_if:format,versus|nullable|string|max:80',
            'side_b_content' => 'nullable|string|max:2000',

            // Duel ciblé
            'opponent_type' => 'nullable|in:user,space',
            'opponent' => 'nullable|string|max:100',
            // Adversaire sans compte : invité par SMS ou WhatsApp
            'opponent_phone' => 'nullable|string|max:20',
            'opponent_phone_code' => 'nullable|string|max:8',
            'opponents' => 'nullable',
            'message' => 'nullable|string|max:280',

            // Invitations (accès "invited")
            'invitees' => 'nullable|array|max:100',
            'invitees.*.type' => 'required_with:invitees|in:user,space',
            'invitees.*.handle' => 'required_with:invitees|string|max:100',
        ], MediaUploader::rules('media'), MediaUploader::rules('side_a_media'), MediaUploader::rules('side_b_media'));
    }

    public function create(User $creator, array $input): Competition
    {
        $data = Validator::make($input, self::createRules(), [
            'cover.required_if' => __('Un concours ou un tournoi a besoin d\'une couverture : une image ou une courte vidéo.'),
        ])->validate();
        $data = array_merge($data, array_intersect_key($input, array_flip(['media', 'side_a_media', 'side_b_media', 'cover'])));
        // Performances : uniquement de vrais fichiers envoyés (le type est lu dans le fichier lui-même)
        foreach (['media', 'side_a_media', 'side_b_media'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = array_values(array_filter((array) $data[$k], fn ($m) => is_array($m) && ($m['file'] ?? null) instanceof UploadedFile));
            }
        }

        $type = $data['type'];
        // Fonctions cachées depuis l'admin
        if (! \App\Support\Features::type($type)) {
            throw new CompetitionException('Ce type de compétition n\'est pas disponible pour le moment.');
        }
        $F = \App\Support\Features::class;
        if ($F::off('space_competitions')) {
            $data['participation_mode'] = 'individual';
        }
        if ($F::off('jury')) {
            unset($data['jurors'], $data['jury_weight']);
            if (($data['vote_audience'] ?? null) === 'jury') {
                $data['vote_audience'] = 'public';
            }
        }
        if ($F::off('applications')) {
            $data['requires_approval'] = false;
        }
        if ($F::off('paid_votes')) {
            $data['vote_cost'] = 0;
        }
        if ($F::off('phone_invites')) {
            unset($data['opponent_phone']);
        }
        if ($F::off('prizes')) {
            unset($data['prize']);
        }
        if ($F::off('versus') && $type === Competition::TYPE_DUEL && (($data['format'] ?? null) === 'versus' || (empty($data['format']) && ! empty($data['side_a_label'])))) {
            throw new CompetitionException('Les versus ne sont pas disponibles pour le moment.');
        }
        $format = $data['format'] ?? match ($type) {
            Competition::TYPE_DUEL => ! empty($data['side_a_label']) ? 'versus' : 'challenge',
            Competition::TYPE_TOURNAMENT => 'single_elimination',
            default => null,
        };

        // Organisateur : Espace (si fourni et géré par le créateur) ou utilisateur
        $organizer = $creator;
        if (! empty($data['space'])) {
            $space = Space::where('slug', $data['space'])->firstOrFail();
            if (! $space->canPublish($creator)) {
                throw new CompetitionException('Il faut être administrateur, modérateur ou publieur de cet Espace pour publier en son nom.', 403);
            }
            $organizer = $space;
        }
        $isSpace = $organizer instanceof Space;
        $limits = config('competition.limits.'.($isSpace ? 'space' : 'user'));

        $mode = $data['participation_mode'] ?? 'individual';
        $access = $data['access'] ?? 'open';
        if ($access === 'members' && ! $isSpace) {
            throw new CompetitionException('L\'option « réservé aux membres » est disponible pour les compétitions d\'un Espace.');
        }
        if ($access === 'members' && $mode === 'space') {
            throw new CompetitionException('Une compétition entre Espaces ne peut pas être réservée aux membres d\'un seul Espace.');
        }

        $maxKey = $type.'_max_entries';
        $limitMax = $limits[$maxKey] ?? null;
        $maxEntries = $data['max_entries'] ?? ($type === Competition::TYPE_TOURNAMENT ? min(8, (int) $limitMax) : $limitMax);
        // Tournoi : un tableau de 4, 8, 16, 32… (puissance de 2), chaque tour divise par deux
        if ($type === Competition::TYPE_TOURNAMENT && ! in_array((int) $maxEntries, config('competition.tournament_sizes'), true)) {
            throw new CompetitionException('Un tournoi se joue à 4, 8, 16, 32, 64… participants (toujours le double du précédent).');
        }
        // Le maximum dépend de qui publie : un compte personnel, ou un Espace (beaucoup plus)
        $who = match ($type) { Competition::TYPE_CHALLENGE => 'challengers', Competition::TYPE_CONTEST => 'candidats', default => 'participants' };
        $what = match ($type) { Competition::TYPE_CHALLENGE => 'un défi', Competition::TYPE_CONTEST => 'un concours', Competition::TYPE_TOURNAMENT => 'un tournoi', default => 'cette compétition' };
        if ($limitMax && $maxEntries > $limitMax) {
            throw new CompetitionException($isSpace
                ? "Un Espace peut accueillir jusqu'à {$limitMax} {$who} dans {$what}."
                : "En ton nom, {$what} accueille jusqu'à {$limitMax} {$who}. Publie depuis un de tes Espaces pour aller plus loin.");
        }
        if ($maxEntries && ! empty($data['min_entries']) && (int) $data['min_entries'] > (int) $maxEntries) {
            throw new CompetitionException("Le minimum ({$data['min_entries']}) ne peut pas dépasser le maximum de {$maxEntries} {$who}.");
        }
        if ($type === Competition::TYPE_CONTEST && $maxEntries && (int) ($data['winners_count'] ?? 1) >= (int) $maxEntries) {
            throw new CompetitionException('Il faut plus de candidats que de lauréats : '.(int) ($data['winners_count'] ?? 1).' lauréat(s) pour '.$maxEntries.' candidats au plus.');
        }
        if (! empty($data['registration_ends_at']) && ! empty($data['ends_at'])
            && \Illuminate\Support\Carbon::parse($data['ends_at'])->lte(\Illuminate\Support\Carbon::parse($data['registration_ends_at']))) {
            throw new CompetitionException('La fin des votes doit venir après la fin des inscriptions.');
        }

        // Le jury existe pour les concours et les tournois (il vote dans chaque duel, avec son propre poids).
        if (! in_array($type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
            unset($data['jurors'], $data['jury_weight']);
            if (($data['vote_audience'] ?? null) === 'jury') {
                $data['vote_audience'] = 'public';
            }
        }
        $juryWeight = (int) ($data['jury_weight'] ?? (! empty($data['jurors']) ? 50 : 0));
        if (($juryWeight > 0 || ! empty($data['jurors'])) && ! $limits['allow_jury']) {
            throw new CompetitionException('Le jury est réservé aux concours et tournois organisés par un Espace.');
        }
        // Récompenses par place (concours et tournois) ; les coins seulement si la monétisation est ouverte
        $prizeTiers = [];
        if (in_array($type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true) && ! empty($data['prize_tiers']) && $F::on('prizes')) {
            $prizeTiers = app(PrizeService::class)->normalize($data['prize_tiers'], (int) $maxEntries ?: null, $F::on('coins'));
            if ($prizeTiers && empty($data['prize'])) {
                $data['prize'] = PrizeService::summary($prizeTiers);
            }
            $need = PrizeService::total($prizeTiers);
            if ($need > 0 && app(\App\Services\Coins\CoinService::class)->balance($creator) < $need) {
                throw new CompetitionException('Pour offrir '.number_format($need, 0, ',', ' ').' coins de récompense, il faut les avoir : tu en as '.number_format((int) $creator->coins, 0, ',', ' ').'. Recharge ton portefeuille ou baisse les montants.', 402);
            }
        }
        if (! empty($data['prize']) && ! $limits['allow_prize']) {
            throw new CompetitionException('Les récompenses annoncées sont réservées aux compétitions d\'un Espace.');
        }
        if (($data['vote_audience'] ?? null) === 'jury' && empty($data['jurors'])) {
            throw new CompetitionException('Ajoute au moins un membre du jury.');
        }
        if ($type === Competition::TYPE_TOURNAMENT && $maxEntries && $maxEntries < 4) {
            throw new CompetitionException('Un tournoi se joue à 4 participants minimum.');
        }

        // Candidatures validées par l'organisateur : photo + description (+ courte vidéo facultative)
        $application = in_array($type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)
            && (bool) ($data['requires_approval'] ?? false);

        if ($access === 'invited' && $type !== Competition::TYPE_DUEL && empty($data['invitees'])) {
            throw new CompetitionException('Sur invitation : ajoute au moins une personne (@pseudo) ou un Espace (#espace) à inviter.');
        }

        // Défi et duel : une seule durée. Pour un défi, tous ses duels se terminent à la fin du défi :
        // les premiers challengers ont donc plus de temps pour récolter des votes.
        $hours = (int) ($data['duration_hours'] ?? $data['match_duration_hours'] ?? config('competition.durations.default_match_hours'));
        if ($type === Competition::TYPE_CHALLENGE && ! empty($data['duration_hours'])) {
            $data['ends_at'] = now()->addHours((int) $data['duration_hours']);
        }

        // Adversaire d'un duel ciblé : un compte (@pseudo, #espace) ou un numéro de téléphone
        $opponent = null;
        $phoneInvite = null;
        if ($type === Competition::TYPE_DUEL && $format === 'challenge') {
            $opponent = $this->resolveHandle($data['opponent_type'] ?? 'user', $data['opponent'] ?? '');
            $phone = preg_replace('/\D+/', '', (string) ($data['opponent_phone'] ?? ''));
            if (! $opponent && $phone !== '') {
                $phoneCode = '+'.ltrim((string) ($data['opponent_phone_code'] ?? '+243'), '+');
                $phone = ltrim($phone, '0');
                // Le numéro appartient peut-être déjà à quelqu'un
                $opponent = User::where('phone_number', $phone)->where('is_active', true)->first();
                if (! $opponent) {
                    $phoneInvite = ['phone_code' => $phoneCode, 'phone_number' => $phone];
                }
            }
            if (! $opponent && ! $phoneInvite) {
                throw new CompetitionException(blank($data['opponent'] ?? null)
                    ? 'Choisis l\'adversaire que tu veux défier.'
                    : 'Personne ne s\'appelle « '.ltrim($data['opponent'], '@#').' » sur Sosa. Indique son numéro : on lui envoie le défi par SMS ou WhatsApp.');
            }
            if ($phoneInvite && $phoneCode === '+'.ltrim((string) $creator->phone_code, '+') && $phone === (string) $creator->phone_number) {
                throw new CompetitionException('Tu ne peux pas te défier toi-même.');
            }
            if ($opponent instanceof User && $opponent->id === $creator->id) {
                throw new CompetitionException('Tu ne peux pas te défier toi-même.');
            }
            if ($opponent instanceof Space && $isSpace && $opponent->id === $organizer->id) {
                throw new CompetitionException('Un Espace ne peut pas se défier lui-même.');
            }
            // Le duel suit la nature des deux camps
            $mode = ($opponent instanceof Space) !== $isSpace ? 'mixed' : ($isSpace ? 'space' : 'individual');
            if (blank($data['message'] ?? null)) {
                $data['message'] = Participant::name($organizer).' te défie ! Montre ce que tu sais faire, le public décidera.';
            }
            $access = 'invited';
            $maxEntries = 2;
        }

        // Duel à deux choix : le texte sous le titre devient la description (il n'y a pas de performance du créateur)
        if ($format === 'versus') {
            if (blank($data['description'] ?? null) && filled($data['content'] ?? null)) {
                $data['description'] = $data['content'];
            }
            unset($data['content'], $data['media']);
        }

        // Un défi ou un duel n'a pas de couverture : c'est la performance du créateur qui s'affiche
        if (in_array($type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true)) {
            unset($data['cover'], $data['cover_duration']);
        }

        // Défi ou duel écrit en une seule ligne (devenue le titre) : ce titre est aussi la performance en texte
        if (in_array($type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true) && $format !== 'versus'
            && blank($data['content'] ?? null) && empty($data['media']) && filled($data['title'] ?? null)) {
            $data['content'] = $data['title'];
        }

        // Format des participations. Défi et duel : celui de la performance du créateur si rien n'est précisé.
        $format_ = $data['entry_format'] ?? null;
        if (! $format_ && in_array($type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true)) {
            $first = collect($data[$format === 'versus' ? 'side_a_media' : 'media'] ?? [])->first();
            $file = is_array($first) ? ($first['file'] ?? null) : null;
            $kind = $first['type'] ?? null;
            if (! $kind && $file instanceof UploadedFile) {
                $mime = (string) $file->getMimeType();
                $kind = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : (str_starts_with($mime, 'image/') ? 'image' : null));
            }
            // Versus (deux choix) : un choix sans média est un choix en texte (son nom suffit) ; l'autre choix suit le même format
            $format_ = $kind ?: ($format === 'versus' ? 'text' : ((($data['content'] ?? null) !== null) ? 'text' : null));
        }
        if ($format_) {
            $data['media_types'] = [$format_];
        }

        // Réservé à une zone (ville, province ou pays) choisie par l'organisateur dans la recherche de lieux.
        // L'organisateur n'a pas besoin d'y être : ce sont les votants et participants qui sont vérifiés.
        $zones = \App\Support\Features::on('geo_limits') ? \App\Support\PlaceSearch::zones((array) ($data['geo_place'] ?? [])) : [];

        // Titre d'un défi publié comme un post : la première ligne du texte, sinon « Défi de … »
        if (blank($data['title'] ?? null) && $type === Competition::TYPE_CHALLENGE) {
            $line = trim((string) strtok(trim((string) ($data['content'] ?? '')), "\n"));
            $data['title'] = mb_strlen($line) >= 3 ? Str::limit($line, 110)
                : 'Défi de '.Participant::name($organizer).(! empty($data['category']) ? ' : '.mb_strtolower(config('competition.categories.'.$data['category'])) : '');
        }
        // Titre d'un duel lancé directement
        if (blank($data['title'] ?? null)) {
            $data['title'] = $format === 'versus'
                ? Str::limit(($data['side_a_label'] ?? 'A').' ou '.($data['side_b_label'] ?? 'B').' ?', 110)
                : Str::limit(Participant::name($organizer).' contre '.($opponent ? Participant::name($opponent) : 'un invité'), 110);
        }

        // Couverture : image ou courte vidéo
        $coverType = null;
        if (($data['cover'] ?? null) instanceof UploadedFile) {
            $coverType = str_starts_with((string) $data['cover']->getMimeType(), 'video/') ? 'video' : 'image';
            if ($coverType === 'video') {
                $this->uploader->assertDuration($data['cover'], 'video', ['duration' => $data['cover_duration'] ?? null], ['video' => (int) config('competition.media.max_cover_video_seconds', 30)]);
            }
        }

        $competition = DB::transaction(function () use ($creator, $organizer, $data, $type, $format, $mode, $access, $maxEntries, $juryWeight, $hours, $opponent, $application, $coverType, $phoneInvite, $zones, $prizeTiers) {
            $cover = null;
            if ($coverType) {
                // Couverture allégée (photo 1600 px ou vidéo 720p) avant d'être rangée
                [$toStore, $ext] = app(\App\Services\MediaCompressor::class)->compress($data['cover'], $coverType);
                $cover = \Illuminate\Support\Facades\Storage::disk(config('filesystems.media', 'public'))
                    ->putFileAs('competitions/covers/'.now()->format('Y/m'), $toStore, \Illuminate\Support\Str::random(40).'.'.$ext);
                if ($toStore instanceof \Illuminate\Http\File) {
                    @unlink($toStore->getRealPath());
                }
            }

            $status = match (true) {
                $type === Competition::TYPE_DUEL && $format === 'versus' => Competition::STATUS_LIVE,
                default => Competition::STATUS_OPEN,
            };

            $endsAt = $data['ends_at'] ?? null;
            if (! $endsAt && $type === Competition::TYPE_CHALLENGE) {
                $endsAt = now()->addDays(7);
            }
            if (! $endsAt && $type === Competition::TYPE_DUEL && $format === 'versus') {
                $endsAt = now()->addHours($hours);
            }

            $c = new Competition([
                'type' => $type,
                'format' => $format,
                'organizer_type' => $organizer::class,
                'organizer_id' => $organizer->getKey(),
                'created_by' => $creator->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'rules' => $data['rules'] ?? null,
                'category' => $data['category'] ?? null,
                'cover' => $cover,
                'cover_type' => $coverType,
                'media_types' => $data['media_types'] ?? null,
                'prize' => $data['prize'] ?? null,
                'participation_mode' => $mode,
                'access' => $access,
                'requires_approval' => (bool) ($data['requires_approval'] ?? false),
                'max_entries' => $maxEntries,
                'min_entries' => $data['min_entries'] ?? 2,
                'vote_audience' => $data['vote_audience'] ?? 'public',
                'member_vote_policy' => $data['member_vote_policy'] ?? 'reduced',
                // Les scores sont toujours visibles
                'blind_votes' => false,
                'jury_weight' => $juryWeight,
                // Zones : la liste complète ; la première sert aussi d'étiquette courte
                'geo_zones' => $zones ?: null,
                'geo_city' => $zones && $zones[0]['level'] !== 'country' ? $zones[0]['label'] : null,
                'geo_country' => $zones[0]['country'] ?? null,
                'geo_country_code' => $zones[0]['country_code'] ?? null,
                'geo_level' => $zones[0]['level'] ?? null,
                'geo_bbox' => $zones[0]['bbox'] ?? null,
                'geo_scope' => $zones ? ($data['geo_scope'] ?? 'both') : null,
                'votes_per_user' => $data['votes_per_user'] ?? 1,
                // Votes payants en coins (concours, duels, tournois) ; un défi reste gratuit
                'vote_cost' => $type === Competition::TYPE_CHALLENGE ? 0 : (int) ($data['vote_cost'] ?? 0),
                'winners_count' => $data['winners_count'] ?? 1,
                'status' => $status,
                'registration_ends_at' => $data['registration_ends_at'] ?? (($type === Competition::TYPE_DUEL && $format === 'challenge') ? now()->addHours(config('competition.durations.invitation_expiry_hours')) : null),
                'starts_at' => $data['starts_at'] ?? ($status === Competition::STATUS_LIVE ? now() : null),
                'ends_at' => $endsAt,
                'match_duration_hours' => $hours,
                'settings' => ['auto_start' => true, 'application' => $application, 'show_duels_in_feed' => (bool) ($data['show_duels_in_feed'] ?? true)],
            ]);
            $c->prize_tiers = $prizeTiers ?: null;
            $c->save();
            // Coins de récompense : retirés tout de suite du portefeuille du créateur, versés à la fin
            if ($prizeTiers) {
                app(PrizeService::class)->escrow($creator, $c, $prizeTiers);
            }

            // Publication dans le fil (likes, commentaires, partages réutilisent l'existant)
            $post = Post::create([
                'authorable_type' => $organizer::class,
                'authorable_id' => $organizer->getKey(),
                'user_id' => $creator->id,
                'type' => 'competition',
                'slug' => 'c-'.$c->slug,
                'content' => $c->description ?: $c->title,
                'status' => 'published',
                'published_at' => now(),
            ]);
            $c->update(['post_id' => $post->id]);

            // Jury
            if (! empty($data['jurors'])) {
                $c->jurors()->sync(User::whereIn('username', $data['jurors'])->pluck('id'));
            }

            // Participation du créateur / côtés du versus
            if ($type === Competition::TYPE_DUEL && $format === 'versus') {
                $a = $this->createEntryRecord($c, null, $creator, [
                    'label' => $data['side_a_label'],
                    'content' => $data['side_a_content'] ?? null,
                    'media' => $this->uploader->storeMany($data['side_a_media'] ?? [], 'competitions/entries', ['video' => (int) config('competition.media.max_video_seconds')]),
                ], 'active');
                $b = $this->createEntryRecord($c, null, $creator, [
                    'label' => $data['side_b_label'],
                    'content' => $data['side_b_content'] ?? null,
                    'media' => $this->uploader->storeMany($data['side_b_media'] ?? [], 'competitions/entries', ['video' => (int) config('competition.media.max_video_seconds')]),
                ], 'active');
                foreach ([$a, $b] as $side) {
                    $this->assertEntryContent($c, $side->content, $side->media ?? [], true);
                }
                $this->matches->open($c, $a, $b, 1, 1, false);
            } elseif (in_array($type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true)) {
                $media = $this->uploader->storeMany($data['media'] ?? [], 'competitions/entries');
                $this->assertEntryContent($c, $data['content'] ?? null, $media);
                $this->createEntryRecord($c, $organizer, $creator, [
                    'content' => $data['content'] ?? null,
                    'media' => $media,
                ], 'active');
            }

            if ($phoneInvite) {
                \App\Models\CompetitionPhoneInvite::create($phoneInvite + [
                    'competition_id' => $c->id,
                    'invited_by' => $creator->id,
                    'message' => $data['message'] ?? null,
                ]);
            }

            if ($opponent) {
                CompetitionInvitation::create([
                    'competition_id' => $c->id,
                    'invitee_type' => $opponent::class,
                    'invitee_id' => $opponent->getKey(),
                    'invited_by' => $creator->id,
                    'message' => $data['message'] ?? null,
                ]);
            }

            return $c;
        });

        $competition->load('organizer');

        // Invitations multiples (accès sur invitation)
        if ($access === 'invited' && ! empty($data['invitees'])) {
            $this->invite($creator, $competition, $data['invitees']);
        }

        // Invitation par numéro : SMS (le lien WhatsApp est proposé à l'écran)
        if ($phoneInvite) {
            $invite = \App\Models\CompetitionPhoneInvite::where('competition_id', $competition->id)->latest('id')->first();
            try {
                if ($invite && app(\App\Services\SmsServiceInterface::class)->send($invite->fullPhone(), $invite->text(), $invite->smsContext())) {
                    $invite->update(['sms_sent_at' => now()]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $this->activities->record($organizer, 'competition.created', $competition, null, ['competition' => $competition->summary()], 1);
        }

        // Fil d'activité + notifications
        if ($opponent) {
            $this->activities->record($organizer, 'duel.invited', $opponent, $competition, [
                'opponent_name' => Participant::name($opponent),
                'competition' => $competition->summary(),
            ], 2);
            $this->notifications->send(
                NotificationService::recipientsFor($opponent),
                'challenge.received',
                Participant::name($organizer).' te défie',
                '« '.$competition->title.' ». Accepte le duel avant '.$competition->registration_ends_at?->format('d/m à H:i').'.',
                $competition->url(),
                $organizer,
                ['competition' => $competition->summary()],
            );
        } else {
            $this->activities->record($organizer, 'competition.created', $competition, null, [
                'competition' => $competition->summary(),
            ], $type === Competition::TYPE_TOURNAMENT || $type === Competition::TYPE_CONTEST ? 2 : 1);

            // Les membres d'un Espace sont prévenus des nouvelles compétitions de leur Espace
            if ($organizer instanceof Space) {
                $this->notifications->send(
                    $organizer->members()->pluck('users.id'),
                    'competition.started',
                    $organizer->name.' lance '.mb_strtolower($competition->typeLabel()).'',
                    '« '.$competition->title.' »',
                    $competition->url(),
                    $organizer,
                    [],
                    $creator->id,
                );
            }
        }

        // Nouveau concours ou tournoi : ceux qui ont déjà voté ou participé sont prévenus (dans la file d'attente)
        if (in_array($competition->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
            \App\Jobs\NotifySupporters::dispatch('newCompetition', [$competition->id]);
        }

        // Les personnes et Espaces cités dans la description sont prévenus
        if (\App\Support\Mentions::handles($competition->description)) {
            \App\Support\Mentions::notify($competition->description, $organizer, 'dans « '.Str::limit($competition->title, 40).' »', $competition->url(), [$creator->id]);
        }

        return $competition->fresh(['organizer', 'entries.participant']);
    }

    // ═════════════════════════════════════════════════════════════
    //  Participations
    // ═════════════════════════════════════════════════════════════

    public static function entryRules(): array
    {
        return array_merge([
            'content' => 'nullable|string|max:5000',
            'as_space' => 'nullable|string|exists:spaces,slug',
            // Candidature : photo, courte vidéo de présentation (facultative)
            'photo' => 'nullable|image|max:10240',
            'video' => 'nullable|file|max:'.MediaUploader::maxKb().'|mimetypes:video/mp4,video/quicktime,video/webm,video/3gpp',
        ], MediaUploader::rules('media'));
    }

    /**
     * Un utilisateur (ou un Espace qu'il représente) participe.
     */
    public function submitEntry(User $user, Competition $c, array $input, ?int $submittedByOverride = null): CompetitionEntry
    {
        $data = Validator::make($input, self::entryRules())->validate();
        $data['media'] = $input['media'] ?? [];

        $participant = $user;
        if (! empty($data['as_space'])) {
            $space = Space::where('slug', $data['as_space'])->firstOrFail();
            if (! $space->canPublish($user)) {
                throw new CompetitionException('Seuls l\'équipe de l\'Espace (administrateurs, modérateurs, publieurs) peut l\'inscrire.', 403);
            }
            $participant = $space;
        }

        $this->assertCanEnter($c, $participant, $user);

        if ($c->isApplication()) {
            if (! ($input['photo'] ?? null) instanceof UploadedFile && ! collect($data['media'])->contains(fn ($m) => ($m['type'] ?? null) === 'image' || (($m['file'] ?? null) instanceof UploadedFile && str_starts_with((string) $m['file']->getMimeType(), 'image/')))) {
                throw new CompetitionException('Ajoute ta photo pour envoyer ta candidature.');
            }
            if (mb_strlen(trim((string) ($data['content'] ?? ''))) < 10) {
                throw new CompetitionException('Présente-toi en quelques mots (10 caractères minimum).');
            }
        }
        $items = [];
        if (($input['photo'] ?? null) instanceof UploadedFile) {
            $items[] = ['file' => $input['photo'], 'type' => 'image'];
        }
        if (($input['video'] ?? null) instanceof UploadedFile) {
            $items[] = ['file' => $input['video'], 'type' => 'video'];
        }
        // Seuls de vrais fichiers envoyés sont acceptés. Un média déjà stocké n'est repris que par le
        // serveur lui-même (proposition choisie par un gestionnaire d'Espace).
        $uploads = $submittedByOverride
            ? $data['media']
            : array_values(array_filter($data['media'], fn ($m) => is_array($m) && ($m['file'] ?? null) instanceof UploadedFile));
        $media = $this->uploader->storeMany(array_merge($items, $uploads), 'competitions/entries',
            $c->isApplication() ? ['video' => (int) config('competition.media.max_intro_video_seconds', 60)] : []);
        if (! $c->isApplication()) {
            try {
                $this->assertEntryContent($c, $data['content'] ?? null, $media);
            } catch (CompetitionException $e) {
                // Mauvais format : on ne garde pas les fichiers envoyés
                foreach ($media as $m) {
                    \Illuminate\Support\Facades\Storage::disk(config('filesystems.media', 'public'))->delete($m['url']);
                }
                throw $e;
            }
        }

        $entry = DB::transaction(function () use ($c, $participant, $user, $data, $media, $submittedByOverride) {
            $locked = Competition::query()->lockForUpdate()->find($c->id);
            if (! $locked->acceptsEntries()) {
                throw new CompetitionException('Les participations sont closes.');
            }

            $status = $locked->requires_approval ? 'pending' : 'active';
            $submitter = $submittedByOverride ? User::find($submittedByOverride) : $user;
            $entry = $this->createEntryRecord($locked, $participant, $submitter, [
                'content' => $data['content'] ?? null,
                'media' => $media,
            ], $status);

            // Invitation acceptée
            CompetitionInvitation::where('competition_id', $locked->id)
                ->where('invitee_type', $participant::class)->where('invitee_id', $participant->getKey())
                ->where('status', 'pending')
                ->update(['status' => 'accepted', 'responded_at' => now()]);

            return $entry;
        });

        $c->refresh();
        $this->afterEntryActivated($c, $entry);

        return $entry->fresh(['participant']);
    }

    private function assertCanEnter(Competition $c, Model $participant, User $user): void
    {
        if (! $c->acceptsEntries()) {
            throw new CompetitionException(match ($c->status) {
                Competition::STATUS_LIVE => 'Les inscriptions sont terminées : place aux votes.',
                Competition::STATUS_COMPLETED => 'Cette compétition est terminée.',
                Competition::STATUS_CANCELLED => 'Cette compétition a été annulée.',
                default => 'Les participations sont closes.',
            });
        }

        // Concours et tournois : ceux qui organisent ou jugent ne concourent pas (ils valident et notent les autres)
        if (in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
            if ($c->canManage($user)) {
                throw new CompetitionException('Tu organises cette compétition : tu ne peux pas y participer.', 403);
            }
            if ($c->isJuror($user)) {
                throw new CompetitionException('Tu fais partie du jury : tu ne peux pas participer.', 403);
            }
        }

        // Réservé à une ville (l'organisateur n'est pas concerné)
        if ((int) $c->created_by !== (int) $user->id) {
            \App\Support\Geo::assert($user, $c, 'enter');
        }

        $isSpace = $participant instanceof Space;
        if ($isSpace && $c->participation_mode === 'individual') {
            throw new CompetitionException('Cette compétition est réservée aux participants individuels.');
        }
        if (! $isSpace && $c->participation_mode === 'space') {
            throw new CompetitionException('Cette compétition oppose des Espaces : inscris un Espace que tu gères.');
        }

        $organizer = $c->organizer;
        if ($organizer && $organizer::class === $participant::class && (int) $organizer->getKey() === (int) $participant->getKey()
            && in_array($c->type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true)) {
            throw new CompetitionException('Tu ne peux pas relever ton propre défi.');
        }

        if ($c->access === 'members') {
            $space = $c->organizerSpace();
            if ($space && ! $space->hasAffiliate($user)) {
                throw new CompetitionException('Rejoins l\'Espace « '.$space->name.' » pour participer.', 403);
            }
        }

        if ($c->access === 'invited') {
            $invited = CompetitionInvitation::where('competition_id', $c->id)
                ->where('invitee_type', $participant::class)->where('invitee_id', $participant->getKey())
                ->whereIn('status', ['pending', 'accepted'])->exists();
            if (! $invited) {
                throw new CompetitionException('Cette compétition est sur invitation.', 403);
            }
        }

        $already = CompetitionEntry::where('competition_id', $c->id)
            ->where('participant_type', $participant::class)->where('participant_id', $participant->getKey())
            ->whereNotIn('status', ['withdrawn'])->exists();
        if ($already) {
            throw new CompetitionException($isSpace ? 'Cet Espace participe déjà.' : 'Tu participes déjà.');
        }
    }

    /** Au plus tant d'adversaires défiés d'un coup (un duel chacun). */
    public const MAX_DUEL_TARGETS = 10;

    /**
     * Les adversaires d'un duel envoyé à plusieurs : « @pseudo », « #espace » ou un numéro de téléphone.
     * Accepte « opponents » (liste ou texte séparé par des virgules) et l'ancien champ unique (opponent / opponent_phone).
     *
     * @return array<int, array<string, string>> les champs d'un duel pour chaque adversaire
     */
    public static function duelTargets(array $input): array
    {
        $raw = $input['opponents'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[,;\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        $code = '+'.ltrim((string) ($input['opponent_phone_code'] ?? '+243'), '+');
        $out = [];
        $add = function (array $t) use (&$out) {
            $out[strtolower(json_encode($t))] = $t;
        };
        foreach ((array) $raw as $r) {
            if (is_array($r)) { // depuis l'app : {handle, type} ou {phone, code}
                if (! empty($r['phone'])) {
                    $add(['opponent_phone' => preg_replace('/\D+/', '', (string) $r['phone']), 'opponent_phone_code' => '+'.ltrim((string) ($r['code'] ?? $code), '+')]);
                } elseif (! empty($r['handle'])) {
                    $add(['opponent' => ltrim((string) $r['handle'], '@#'), 'opponent_type' => ($r['type'] ?? 'user') === 'space' ? 'space' : 'user']);
                }

                continue;
            }
            $r = trim((string) $r);
            if ($r === '') {
                continue;
            }
            if (preg_match('/^\+?[\d\s().\-]{6,}$/', $r)) {
                $digits = preg_replace('/\D+/', '', $r);
                $cc = ltrim($code, '+');
                $c = $code;
                if (str_starts_with($r, '+')) {
                    if (str_starts_with($digits, $cc)) {
                        $digits = substr($digits, strlen($cc));
                    } else { // autre pays : indicatif de 1 à 3 chiffres, on garde l'écriture complète
                        $c = '+'.substr($digits, 0, 3);
                        $digits = substr($digits, 3);
                    }
                }
                $add(['opponent_phone' => ltrim($digits, '0'), 'opponent_phone_code' => $c]);
            } else {
                $add(['opponent' => ltrim($r, '@#'), 'opponent_type' => str_starts_with($r, '#') ? 'space' : 'user']);
            }
        }
        // L'ancien champ unique (saisi sans être ajouté à la liste) compte aussi
        if (filled($input['opponent'] ?? null)) {
            $add(['opponent' => ltrim((string) $input['opponent'], '@#'), 'opponent_type' => ($input['opponent_type'] ?? 'user') === 'space' ? 'space' : 'user']);
        } elseif (filled($input['opponent_phone'] ?? null)) {
            $add(['opponent_phone' => ltrim(preg_replace('/\D+/', '', (string) $input['opponent_phone']), '0'), 'opponent_phone_code' => $code]);
        }

        return array_values($out);
    }

    /**
     * Un duel pour chaque adversaire : chacun reçoit son défi, individuellement.
     * Si certains échouent (pseudo inconnu…), les autres partent quand même.
     *
     * @return array{0: array<int, Competition>, 1: array<int, string>} les duels créés, et ce qui n'a pas marché
     */
    public function createDuels(User $creator, array $input): array
    {
        $targets = self::duelTargets($input);
        if (count($targets) <= 1) {
            return [[$this->create($creator, array_merge(array_diff_key($input, ['opponents' => 1]), $targets[0] ?? []))], []];
        }
        if (count($targets) > self::MAX_DUEL_TARGETS) {
            throw new CompetitionException('Tu peux défier jusqu\'à '.self::MAX_DUEL_TARGETS.' personnes à la fois.');
        }
        $base = array_diff_key($input, array_flip(['opponents', 'opponent', 'opponent_type', 'opponent_phone']));
        $made = [];
        $errors = [];
        foreach ($targets as $t) {
            try {
                $made[] = $this->create($creator, array_merge($base, $t));
            } catch (CompetitionException $e) {
                $who = isset($t['opponent']) ? ($t['opponent_type'] === 'space' ? '#' : '@').$t['opponent'] : $t['opponent_phone_code'].' '.$t['opponent_phone'];
                $errors[] = $who.' : '.$e->getMessage();
            }
        }
        if (! $made) {
            throw new CompetitionException(implode(' ', $errors));
        }

        return [$made, $errors];
    }

    private function assertEntryContent(Competition $c, ?string $content, array $media, bool $versusSide = false): void
    {
        // Format imposé : on répond comme le créateur (vidéo contre vidéo, texte contre texte…)
        if ($fmt = $c->entryFormat()) {
            $label = mb_strtolower(Competition::FORMAT_LABELS[$fmt] ?? $fmt);
            if ($fmt === 'text') {
                if (blank($content) && ! $versusSide) {
                    throw new CompetitionException('Ici, on répond en texte, comme le créateur : écris ta réponse.');
                }
                if (! empty($media)) {
                    throw new CompetitionException($versusSide
                        ? 'Les deux choix doivent avoir le même format : le premier est en texte, retire le média du second (ou ajoute le même type de média au premier).'
                        : 'Ici, on répond en texte uniquement, comme le créateur : retire le média.');
                }

                return;
            }
            if (! collect($media)->contains(fn ($m) => ($m['type'] ?? null) === $fmt)) {
                throw new CompetitionException($versusSide
                    ? 'Les deux choix doivent avoir le même format : ajoute aussi '.(['video' => 'une vidéo', 'audio' => 'un audio', 'image' => 'une photo'][$fmt] ?? $label).' au second choix.'
                    : 'Ici, on répond en '.$label.', comme le créateur. Ajoute ta '.$label.'.');
            }
            foreach ($media as $m) {
                if (($m['type'] ?? null) !== $fmt) {
                    throw new CompetitionException('Seul le format '.$label.' est accepté ici.');
                }
            }

            return;
        }
        if ($versusSide) {
            return; // le nom de la proposition suffit
        }
        if (blank($content) && empty($media)) {
            throw new CompetitionException('Ajoute un texte ou un média à ta participation.');
        }
        foreach ($media as $m) {
            if (! $c->allowsMedia($m['type'])) {
                throw new CompetitionException('Format « '.$m['type'].' » non accepté dans cette compétition.');
            }
        }
        if (empty($media) && ! $c->allowsMedia('text')) {
            throw new CompetitionException('Cette compétition demande un média (photo, vidéo ou audio).');
        }
    }

    private function createEntryRecord(Competition $c, ?Model $participant, ?User $submitter, array $attrs, string $status): CompetitionEntry
    {
        // Une participation retirée peut être remplacée
        if ($participant) {
            CompetitionEntry::where('competition_id', $c->id)
                ->where('participant_type', $participant::class)->where('participant_id', $participant->getKey())
                ->where('status', 'withdrawn')->delete();
        }

        $entry = CompetitionEntry::create(array_merge([
            'competition_id' => $c->id,
            'participant_type' => $participant ? $participant::class : null,
            'participant_id' => $participant?->getKey(),
            'submitted_by' => $submitter?->id,
            'status' => $status,
        ], $attrs));

        if ($status === 'active') {
            $c->increment('entries_count');
        }

        return $entry;
    }

    /** Effets d'une participation devenue active (directement ou après validation). */
    private function afterEntryActivated(Competition $c, CompetitionEntry $entry): void
    {
        $entry->loadMissing('participant');
        $participant = $entry->participant;

        if ($entry->status === 'pending') {
            $this->notifications->send(
                NotificationService::recipientsFor($c->organizer),
                'entry.submitted',
                'Nouvelle candidature',
                $entry->displayName().' veut participer à « '.$c->title.' ». À toi de valider.',
                $c->url().'?tab=manage',
                $participant,
            );

            return;
        }

        // Défi / duel : l'affrontement démarre tout de suite contre le créateur
        $match = null;
        if (in_array($c->type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true)) {
            $owner = $this->ownerEntry($c);
            if ($owner && $owner->id !== $entry->id) {
                $match = $this->matches->open($c, $owner, $entry);
                if ($c->type === Competition::TYPE_DUEL) {
                    $c->update(['status' => Competition::STATUS_LIVE, 'starts_at' => now(), 'ends_at' => $match->ends_at]);
                }
            }
        }

        if ($participant) {
            if ($match) {
                // L'organisateur d'un défi choisit si chaque duel apparaît dans le fil
                $inFeed = $c->type !== Competition::TYPE_CHALLENGE || ($c->settings['show_duels_in_feed'] ?? true);
                $this->activities->record($participant, 'challenge.accepted', $match, $c, [
                    'owner_name' => Participant::name($c->organizer),
                    'competition' => $c->summary(),
                ], 2, $inFeed ? 'public' : 'private');
            } else {
                $this->activities->record($participant, 'entry.submitted', $c, $c->organizerSpace(), [
                    'competition' => $c->summary(),
                ]);
            }

            $this->ratings->addPoints($participant, (int) config('competition.points.participation'), $c->category);
            $this->badges->award($participant, 'first_entry');
        }

        if ($c->type !== Competition::TYPE_DUEL) {
            $this->notifications->send(
                NotificationService::recipientsFor($c->organizer),
                $match ? 'challenge.accepted' : 'entry.submitted',
                $match ? $entry->displayName().' a relevé ton défi' : 'Nouveau participant',
                $match ? '« '.$c->title.' » : le duel commence. Partage-le pour récolter des votes.' : $entry->displayName().' participe à « '.$c->title.' ».',
                $match ? $match->url() : $c->url(),
                $participant,
                [],
                $entry->submitted_by,
            );
        }

        // Ceux qui ont déjà voté pour ce participant ailleurs : « ton favori est de retour »
        if ($participant) {
            \App\Jobs\NotifySupporters::dispatch('favoriteJoined', [$entry->id]);
        }

        // Personnes ou Espaces cités dans le texte de la participation
        if ($participant && \App\Support\Mentions::handles($entry->content)) {
            \App\Support\Mentions::notify($entry->content, $participant, 'dans sa participation à « '.Str::limit($c->title, 40).' »', $match ? $match->url() : $c->url(), array_filter([(int) $entry->submitted_by]));
        }

        // Badge organisateur à 10 participants
        if ($c->entries_count >= 10 && $c->organizer) {
            $this->badges->award($c->organizer, 'organizer', ['competition' => $c->slug]);
        }

        // Tournoi complet : lancement automatique
        if ($c->type === Competition::TYPE_TOURNAMENT && $c->max_entries && $c->entries_count >= $c->max_entries && ($c->settings['auto_start'] ?? true)) {
            app(TournamentService::class)->start($c->fresh());
        }
    }

    public function ownerEntry(Competition $c): ?CompetitionEntry
    {
        return CompetitionEntry::where('competition_id', $c->id)
            ->where('participant_type', $c->organizer_type)
            ->where('participant_id', $c->organizer_id)
            ->first();
    }

    public function approveEntry(User $manager, CompetitionEntry $entry): CompetitionEntry
    {
        $c = $entry->competition;
        $this->assertManager($manager, $c);
        if ($entry->status !== 'pending') {
            throw new CompetitionException('Cette candidature a déjà été traitée.');
        }
        if (! $c->acceptsEntries() && $c->status !== Competition::STATUS_OPEN) {
            throw new CompetitionException('Les participations sont closes.');
        }

        $entry->update(['status' => 'active']);
        $c->increment('entries_count');
        $this->notifications->send($entry->stakeholderIds(), 'entry.approved', 'Candidature acceptée', 'Tu participes à « '.$c->title.' ».', $c->url(), $c->organizer);
        $this->afterEntryActivated($c->fresh(), $entry);

        return $entry->fresh();
    }

    public function rejectEntry(User $manager, CompetitionEntry $entry, ?string $reason = null): CompetitionEntry
    {
        $c = $entry->competition;
        $this->assertManager($manager, $c);
        $wasActive = $entry->status === 'active';
        if (! in_array($entry->status, ['pending', 'active'], true) || ($wasActive && $c->status !== Competition::STATUS_OPEN)) {
            throw new CompetitionException('Cette participation ne peut plus être refusée.');
        }

        $entry->update(['status' => 'rejected', 'rejection_reason' => $reason]);
        if ($wasActive) {
            $c->decrement('entries_count');
        }
        $this->notifications->send($entry->stakeholderIds(), 'entry.rejected', 'Candidature refusée', '« '.$c->title.' »'.($reason ? ' : '.$reason : '.'), $c->url(), $c->organizer);

        return $entry->fresh();
    }

    public function withdrawEntry(User $user, CompetitionEntry $entry): void
    {
        $c = $entry->competition;
        if (! in_array((int) $user->id, $entry->stakeholderIds(), true)) {
            throw new CompetitionException('Ce n\'est pas ta participation.', 403);
        }
        $owner = $this->ownerEntry($c);
        if ($owner && $owner->id === $entry->id) {
            throw new CompetitionException('L\'organisateur ne peut pas retirer sa participation. Annule la compétition à la place.');
        }
        $hasLiveMatch = CompetitionMatch::where('competition_id', $c->id)
            ->where('status', 'voting')
            ->where(fn ($q) => $q->where('entry_a_id', $entry->id)->orWhere('entry_b_id', $entry->id))
            ->exists();
        if ($hasLiveMatch || $c->status !== Competition::STATUS_OPEN || in_array($entry->status, ['withdrawn', 'rejected'], true)) {
            throw new CompetitionException('Impossible de te retirer : ton duel a déjà commencé ou la compétition est lancée.');
        }

        $wasActive = $entry->status === 'active';
        $entry->update(['status' => 'withdrawn']);
        if ($wasActive) {
            $c->decrement('entries_count');
        }
    }

    /** L'organisateur ajoute directement un candidat (concours / tournoi). */
    public function addCandidate(User $manager, Competition $c, array $input): CompetitionEntry
    {
        $this->assertManager($manager, $c);
        if (! in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
            throw new CompetitionException('On ne peut ajouter des candidats que dans un concours ou un tournoi.');
        }
        if ($c->status !== Competition::STATUS_OPEN) {
            throw new CompetitionException('La compétition a déjà commencé.');
        }
        $data = Validator::make($input, array_merge([
            'label' => 'required|string|max:80',
            'content' => 'nullable|string|max:5000',
            'username' => 'nullable|string|exists:users,username',
        ], MediaUploader::rules('media')))->validate();

        $participant = ! empty($data['username']) ? User::where('username', $data['username'])->first() : null;
        // L'organisateur (et les gestionnaires) organisent : ils ne concourent pas
        if ($participant && $c->canManage($participant)) {
            throw new CompetitionException('L\'organisateur ne peut pas être candidat de sa propre compétition.');
        }
        $entry = $this->createEntryRecord($c, $participant, $manager, [
            'label' => $data['label'],
            'content' => $data['content'] ?? null,
            'media' => $this->uploader->storeMany($input['media'] ?? [], 'competitions/entries'),
        ], 'active');

        return $entry;
    }

    // ═════════════════════════════════════════════════════════════
    //  Invitations
    // ═════════════════════════════════════════════════════════════

    /**
     * @param  array<int, array{type: string, handle: string}>  $invitees
     */
    public function invite(User $manager, Competition $c, array $invitees, ?string $message = null): int
    {
        $this->assertManager($manager, $c);
        $count = 0;
        foreach ($invitees as $inv) {
            $target = $this->resolveHandle($inv['type'] ?? 'user', $inv['handle'] ?? '');
            if (! $target) {
                continue;
            }
            $invitation = CompetitionInvitation::firstOrCreate([
                'competition_id' => $c->id,
                'invitee_type' => $target::class,
                'invitee_id' => $target->getKey(),
            ], ['invited_by' => $manager->id, 'message' => $message]);

            if ($invitation->wasRecentlyCreated) {
                $count++;
                $this->notifications->send(
                    NotificationService::recipientsFor($target),
                    'challenge.received',
                    'Invitation : '.$c->typeLabel().'',
                    Participant::name($c->organizer).' t\'invite à « '.$c->title.' ».',
                    $c->url(),
                    $c->organizer,
                    ['invitation_id' => $invitation->id],
                );
            }
        }

        return $count;
    }

    public function acceptInvitation(User $user, CompetitionInvitation $invitation, array $input): CompetitionEntry
    {
        if (! $invitation->canRespond($user)) {
            throw new CompetitionException('Cette invitation ne t\'est pas adressée.', 403);
        }
        if ($invitation->status !== 'pending') {
            throw new CompetitionException('Cette invitation a déjà reçu une réponse.');
        }
        if ($invitation->invitee_type === Space::class) {
            $input['as_space'] = $invitation->invitee->slug;
        } else {
            unset($input['as_space']);
        }

        return $this->submitEntry($user, $invitation->competition, $input);
    }

    public function declineInvitation(User $user, CompetitionInvitation $invitation): void
    {
        if (! $invitation->canRespond($user)) {
            throw new CompetitionException('Cette invitation ne t\'est pas adressée.', 403);
        }
        if ($invitation->status !== 'pending') {
            throw new CompetitionException('Cette invitation a déjà reçu une réponse.');
        }
        $invitation->update(['status' => 'declined', 'responded_at' => now()]);
        $c = $invitation->competition;

        if ($c->type === Competition::TYPE_DUEL && $c->status === Competition::STATUS_OPEN) {
            $c->update(['status' => Competition::STATUS_CANCELLED]);
        }
        $this->notifications->send([$invitation->invited_by], 'invitation.declined', 'Défi refusé',
            Participant::name($invitation->invitee).' a décliné « '.$c->title.' ».', $c->url(), $invitation->invitee);
    }

    // ═════════════════════════════════════════════════════════════
    //  Propositions internes d'un Espace
    // ═════════════════════════════════════════════════════════════

    public function propose(User $user, Competition $c, Space $space, array $input): EntryProposal
    {
        if (! $space->hasAffiliate($user)) {
            throw new CompetitionException('Rejoins l\'Espace pour proposer une participation.', 403);
        }
        if ($c->participation_mode === 'individual' || ! $c->acceptsEntries()) {
            throw new CompetitionException('Cet Espace ne peut pas (ou plus) participer à cette compétition.');
        }
        $data = Validator::make($input, array_merge(['content' => 'nullable|string|max:5000'], MediaUploader::rules('media')))->validate();
        $media = $this->uploader->storeMany(array_values(array_filter((array) ($input['media'] ?? []), fn ($m) => is_array($m) && ($m['file'] ?? null) instanceof UploadedFile)), 'competitions/proposals');
        $this->assertEntryContent($c, $data['content'] ?? null, $media);

        $proposal = EntryProposal::updateOrCreate(
            ['competition_id' => $c->id, 'space_id' => $space->id, 'user_id' => $user->id],
            ['content' => $data['content'] ?? null, 'media' => $media, 'status' => 'pending']
        );

        $this->notifications->send(NotificationService::recipientsFor($space), 'proposal.submitted', 'Nouvelle proposition',
            Participant::name($user).' propose de représenter '.$space->name.' dans « '.$c->title.' ».', $c->url().'?tab=proposals', $user, [], $user->id);

        return $proposal;
    }

    public function selectProposal(User $manager, EntryProposal $proposal): CompetitionEntry
    {
        $space = $proposal->space;
        if (! $space->canManage($manager)) {
            throw new CompetitionException('Seuls les gestionnaires de l\'Espace choisissent sa participation.', 403);
        }
        $entry = $this->submitEntry($manager, $proposal->competition, [
            'content' => $proposal->content,
            'media' => collect($proposal->media ?? [])->map(fn ($m) => ['url' => $m['url'], 'type' => $m['type'], 'metadata' => $m['metadata'] ?? null])->all(),
            'as_space' => $space->slug,
        ], $proposal->user_id);

        $proposal->update(['status' => 'selected']);
        EntryProposal::where('competition_id', $proposal->competition_id)->where('space_id', $space->id)
            ->where('id', '!=', $proposal->id)->update(['status' => 'declined']);

        return $entry;
    }

    // ═════════════════════════════════════════════════════════════
    //  Lancement, annulation, fin
    // ═════════════════════════════════════════════════════════════

    public function start(User $manager, Competition $c): Competition
    {
        $this->assertManager($manager, $c);

        return $this->startNow($c);
    }

    /** Démarre un concours ou un tournoi (manuel ou par la tâche planifiée). */
    public function startNow(Competition $c): Competition
    {
        if ($c->type === Competition::TYPE_TOURNAMENT) {
            return app(TournamentService::class)->start($c);
        }
        if ($c->type !== Competition::TYPE_CONTEST) {
            throw new CompetitionException('Les défis et duels démarrent automatiquement.');
        }
        if ($c->status !== Competition::STATUS_OPEN) {
            throw new CompetitionException('Ce concours a déjà commencé.');
        }
        $count = $c->entries()->where('status', 'active')->count();
        if ($count < max(2, (int) $c->min_entries)) {
            throw new CompetitionException('Il faut au moins '.max(2, (int) $c->min_entries).' candidats pour ouvrir les votes.');
        }

        $endsAt = $c->ends_at && $c->ends_at->isFuture() ? $c->ends_at : now()->addHours(max(24, (int) $c->match_duration_hours));
        $c->update(['status' => Competition::STATUS_LIVE, 'starts_at' => now(), 'ends_at' => $endsAt]);

        $this->activities->record($c->organizer, 'contest.voting', $c, null, [
            'entries' => $count,
            'competition' => $c->summary(),
        ], 2);
        $this->notifications->send(
            $c->entries()->where('status', 'active')->get()->flatMap(fn ($e) => $e->stakeholderIds()),
            'competition.started', 'Les votes sont ouverts',
            '« '.$c->title.' » : partage ta participation pour récolter des voix jusqu\'au '.$endsAt->format('d/m à H:i').'.',
            $c->url(), $c->organizer
        );

        return $c->fresh();
    }

    public function cancel(User $manager, Competition $c, ?string $reason = null): void
    {
        $this->assertManager($manager, $c);
        if (in_array($c->status, [Competition::STATUS_COMPLETED, Competition::STATUS_CANCELLED], true)) {
            throw new CompetitionException('Cette compétition est déjà terminée.');
        }

        DB::transaction(function () use ($c) {
            $c->update(['status' => Competition::STATUS_CANCELLED, 'completed_at' => now()]);
            $c->matches()->where('status', 'voting')->update(['status' => 'cancelled']);
        });
        app(PrizeService::class)->refund($c);

        $this->notifications->send(
            $c->entries()->whereIn('status', ['active', 'pending'])->get()->flatMap(fn ($e) => $e->stakeholderIds()),
            'competition.completed', 'Compétition annulée',
            '« '.$c->title.' » a été annulée'.($reason ? ' : '.$reason : '.'),
            $c->url(), $c->organizer, [], $manager->id
        );
    }

    /** Défi : terminé quand la date de fin est passée et qu'aucun duel n'est en cours. */
    public function completeChallengeIfDone(Competition $c): void
    {
        if ($c->type !== Competition::TYPE_CHALLENGE || in_array($c->status, [Competition::STATUS_COMPLETED, Competition::STATUS_CANCELLED], true)) {
            return;
        }
        $closedToNew = ($c->ends_at && $c->ends_at->isPast()) || ($c->max_entries && $c->entries_count >= $c->max_entries);
        if (! $closedToNew || $c->matches()->where('status', 'voting')->exists()) {
            return;
        }
        $this->complete($c);
    }

    /**
     * Fin d'une compétition : classement final, gagnants, trophées, badges, fil et notifications.
     */
    public function complete(Competition $c): Competition
    {
        $done = DB::transaction(function () use ($c) {
            $c = Competition::query()->lockForUpdate()->find($c->id);
            if (in_array($c->status, [Competition::STATUS_COMPLETED, Competition::STATUS_CANCELLED], true)) {
                return null;
            }

            match ($c->type) {
                Competition::TYPE_CONTEST => $this->rankContest($c),
                Competition::TYPE_TOURNAMENT => $this->rankTournament($c),
                Competition::TYPE_DUEL => $c->winner_entry_id = $c->matches()->value('winner_entry_id'),
                Competition::TYPE_CHALLENGE => $this->rankChallenge($c),
            };

            $c->status = Competition::STATUS_COMPLETED;
            $c->completed_at = now();
            $c->save();

            return $c;
        });

        if (! $done) {
            return $c->fresh();
        }

        $c = $done->fresh(['organizer', 'winnerEntry.participant']);
        $winner = $c->winnerEntry;
        // Les coins de récompense partent aux gagnants (le reste revient au créateur)
        try {
            app(PrizeService::class)->payout($c);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($c->type === Competition::TYPE_DUEL) {
            return $c; // le résultat du duel est déjà dans le fil (match.completed)
        }

        // Trophées et points
        $points = config('competition.points');
        if ($c->type === Competition::TYPE_TOURNAMENT && $winner?->participant) {
            $this->ratings->addPoints($winner->participant, $points['tournament_win'], $c->category, true);
            $this->badges->award($winner->participant, 'champion', ['competition' => $c->slug]);
            $finalist = $c->entries()->where('rank', 2)->with('participant')->first();
            if ($finalist?->participant) {
                $this->ratings->addPoints($finalist->participant, $points['tournament_final'], $c->category);
            }
        }
        if ($c->type === Competition::TYPE_CONTEST) {
            foreach ($c->entries()->whereNotNull('rank')->where('rank', '<=', 3)->with('participant')->get() as $podium) {
                if ($podium->participant) {
                    $this->ratings->addPoints($podium->participant, $points['contest_podium'][$podium->rank] ?? 0, $c->category, $podium->rank === 1);
                    if ($podium->rank === 1) {
                        $this->badges->award($podium->participant, 'laureate', ['competition' => $c->slug]);
                    }
                }
            }
        }

        $actor = $winner?->participant ?? $c->organizer;
        if ($actor) {
            $owner = $c->type === Competition::TYPE_CHALLENGE ? $this->ownerEntry($c) : null;
            $this->activities->record($actor, 'competition.completed', $c, $c->organizerSpace(), [
                'winner_name' => $winner?->displayName(),
                'entries' => $c->entries_count,
                'votes' => $c->votes_count,
                'owner_wins' => $owner?->wins,
                'owner_losses' => $owner?->losses,
                'competition' => $c->summary(),
                // Concours : le classement final, pour la carte du fil
                'standings' => $c->type === Competition::TYPE_CONTEST ? \App\Support\FeedCards::standings($c, true) : null,
            ], 3);
        }

        $this->notifications->send(
            $c->entries()->whereIn('status', ['active', 'winner', 'eliminated'])->get()->flatMap(fn ($e) => $e->stakeholderIds())
                ->merge(NotificationService::recipientsFor($c->organizer)),
            'competition.completed', 'Résultats : « '.Str::limit($c->title, 40).' »',
            $winner ? $winner->displayName().' remporte la compétition.' : 'La compétition est terminée. Découvre le classement.',
            $c->url(), $c->organizer
        );
        // Les votants apprennent la place de leur favori (dans la file d'attente)
        \App\Jobs\NotifySupporters::dispatch('competitionCompleted', [$c->id]);
        // Une récompense annoncée : on invite l'organisateur à filmer la remise du prix
        try {
            app(\App\Services\Social\Pulse::class)->prizeReminder($c);
        } catch (\Throwable $e) {
            report($e);
        }

        return $c;
    }

    private function rankContest(Competition $c): void
    {
        $entries = $c->entries()->where('status', 'active')->get();
        $jw = $c->jury_weight / 100;
        $maxPublic = max(0.0001, (float) $entries->max('score'));
        $jury = CompetitionJuryScore::where('competition_id', $c->id)->get()->groupBy('entry_id')->map->avg('score');
        $hasJury = $jury->isNotEmpty() && $jw > 0;

        $ranked = $entries->map(function (CompetitionEntry $e) use ($maxPublic, $jury, $jw, $hasJury) {
            $public = $e->score / $maxPublic * 100;
            $final = $hasJury ? (1 - $jw) * $public + $jw * (($jury[$e->id] ?? 0) * 10) : $public;
            $e->setAttribute('final', round($final, 3));

            return $e;
        })->sortBy([
            fn ($a, $b) => $b->final <=> $a->final,
            fn ($a, $b) => $b->votes_count <=> $a->votes_count,
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();

        foreach ($ranked as $i => $e) {
            $rank = $i + 1;
            CompetitionEntry::whereKey($e->id)->update([
                'rank' => $rank,
                'score' => $e->final,
                'status' => $rank <= $c->winners_count ? 'winner' : 'active',
            ]);
        }
        $c->winner_entry_id = $ranked->first()?->id;
    }

    private function rankTournament(Competition $c): void
    {
        $total = max(1, $c->totalRounds());
        $final = $c->matches()->where('round', $total)->first();
        $winnerId = $c->winner_entry_id ?? $final?->winner_entry_id;

        foreach ($c->matches()->where('is_bye', false)->get() as $m) {
            $loser = (int) $m->winner_entry_id === (int) $m->entry_a_id ? $m->entry_b_id : $m->entry_a_id;
            if ($loser) {
                CompetitionEntry::whereKey($loser)->update(['rank' => 2 ** ($total - $m->round) + 1, 'status' => 'eliminated']);
            }
        }
        if ($winnerId) {
            CompetitionEntry::whereKey($winnerId)->update(['rank' => 1, 'status' => 'winner']);
        }
        $c->winner_entry_id = $winnerId;
    }

    private function rankChallenge(Competition $c): void
    {
        // Classement par victoires puis votes : le créateur et ceux qui l'ont battu en tête.
        $entries = $c->entries()->where('status', 'active')->orderByDesc('wins')->orderByDesc('votes_count')->get();
        foreach ($entries as $i => $e) {
            $e->update(['rank' => $i + 1]);
        }
        $c->winner_entry_id = $entries->first()?->id;
    }

    // ═════════════════════════════════════════════════════════════
    //  Revanche
    // ═════════════════════════════════════════════════════════════

    public function rematch(User $user, CompetitionMatch $match): Competition
    {
        $match->load(['competition', 'entryA.participant', 'entryB.participant']);
        if ($match->status !== 'completed' || $match->is_bye) {
            throw new CompetitionException('La revanche est possible une fois le duel terminé.');
        }
        if ($match->competition->type === Competition::TYPE_TOURNAMENT) {
            throw new CompetitionException('Pas de revanche dans un tournoi : rendez-vous au prochain !');
        }

        $mine = collect([$match->entryA, $match->entryB])->first(fn ($e) => $e && in_array((int) $user->id, $e->stakeholderIds(), true));
        $other = $mine === $match->entryA ? $match->entryB : $match->entryA;
        if (! $mine || ! $mine->participant || ! $other?->participant) {
            throw new CompetitionException('Seuls les participants du duel peuvent demander une revanche.', 403);
        }

        $input = [
            'type' => Competition::TYPE_DUEL,
            'format' => 'challenge',
            'title' => 'Revanche : '.Str::limit($match->competition->title, 100),
            'description' => $match->competition->description,
            'category' => $match->competition->category,
            'content' => $mine->content ?: '',
            'opponent_type' => Participant::type($other->participant),
            'opponent' => $other->participant instanceof Space ? $other->participant->slug : $other->participant->username,
            'match_duration_hours' => $match->competition->match_duration_hours,
            'message' => 'Je veux ma revanche !',
        ];
        if ($mine->participant instanceof Space) {
            $input['space'] = $mine->participant->slug;
        }
        $input['media'] = collect($mine->media ?? [])->map(fn ($m) => ['url' => $m['url'], 'type' => $m['type'], 'metadata' => $m['metadata'] ?? null])->all();

        $duel = $this->create($user, $input);
        $duel->update(['settings' => array_merge($duel->settings ?? [], ['rematch_of' => $match->id])]);

        $this->notifications->send(NotificationService::recipientsFor($other->participant), 'rematch.requested', 'Revanche demandée',
            $mine->displayName().' réclame une revanche. Tu relèves ?', $duel->url(), $mine->participant);

        return $duel;
    }

    // ═════════════════════════════════════════════════════════════
    //  Outils
    // ═════════════════════════════════════════════════════════════

    private function assertManager(User $user, Competition $c): void
    {
        if (! $c->canManage($user) && ! $user->is_admin) {
            throw new CompetitionException('Seul l\'organisateur peut faire cette action.', 403);
        }
    }

    /**
     * Invitations envoyées par numéro : à l'inscription (ou en ouvrant le lien reçu),
     * elles deviennent des invitations normales et le défié est notifié.
     */
    public function claimPhoneInvites(User $user, ?string $token = null): int
    {
        $q = \App\Models\CompetitionPhoneInvite::with('competition')->where('status', 'pending');
        $token
            ? $q->where('token', $token)
            : $q->where('phone_number', ltrim((string) $user->phone_number, '0'));
        $count = 0;
        foreach ($q->get() as $invite) {
            $c = $invite->competition;
            if (! $c || (int) $invite->invited_by === (int) $user->id) {
                continue;
            }
            CompetitionInvitation::firstOrCreate(
                ['competition_id' => $c->id, 'invitee_type' => User::class, 'invitee_id' => $user->id],
                ['invited_by' => $invite->invited_by, 'message' => $invite->message],
            );
            $invite->update(['status' => 'claimed', 'claimed_by' => $user->id]);
            $this->notifications->send(
                [$user->id],
                'challenge.received',
                Participant::name($c->organizer).' te défie',
                '« '.$c->title.' ». Accepte le duel pour commencer.',
                $c->url(),
                $c->organizer,
                ['competition' => $c->summary()],
            );
            $count++;
        }

        return $count;
    }

    private function resolveHandle(string $type, string $handle): ?Model
    {
        $handle = ltrim(trim($handle), '@');
        if ($handle === '') {
            return null;
        }

        return $type === 'space'
            ? Space::where('slug', $handle)->where('is_active', true)->first()
            : User::where('username', $handle)->where('is_active', true)->first();
    }
}
