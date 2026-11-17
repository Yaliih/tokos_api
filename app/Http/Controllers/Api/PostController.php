<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostView;
use App\Models\PostVote;
use App\Models\Space;
use App\Models\User;
use App\Models\UserInteraction;
use App\Services\RecommendationService;
use App\Services\VoteCounterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Rules\MaxAudioDuration;

class PostController extends Controller
{
    protected $recommendation;
    protected $voteCounter;

    public function __construct(RecommendationService $recommendation, VoteCounterService $voteCounter)
    {
        $this->recommendation = $recommendation;
        $this->voteCounter = $voteCounter;
    }

    /**
     * Feed personnalisé
     */
    public function feed(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, min(50, (int) $request->integer('per_page', 20)));

        $feed = $this->recommendation->getPersonalizedFeed(
            $request->user(),
            $page,
            $perPage,
            $request->query('type')
        );

        return response()->json([
            'success' => true,
            'data' => $feed['data'],
            'pagination' => [
                'current_page' => $feed['current_page'],
                'last_page' => $feed['last_page'],
                'per_page' => $feed['per_page'],
                'total' => $feed['total'],
            ],
            'has_more' => $feed['current_page'] < $feed['last_page'],
        ]);
    }

    /**
     * Récupérer les reels
     */
    public function reels(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->recommendation->getReels($request->user()),
        ]);
    }

    /**
     * Charger plus de posts
     */
    public function loadMore(Request $request): JsonResponse
    {
        // Plus de reshuffle automatique : il provoquait des doublons et
        // empêchait le frontend de considérer réellement le feed terminé.
        return $this->feed($request);
    }

    /**
     * Upload d'un fichier
     */
    private function uploadFile($file, string $folder = 'posts'): string
    {
        $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($folder, $filename, config('filesystems.media', 'public'));
        return $path;
    }

    /**
     * Upload de fichiers multiples
     */
    private function uploadMultipleFiles(array $files, string $folder = 'posts'): array
    {
        $uploaded = [];
        foreach ($files as $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $uploaded[] = $this->uploadFile($file, $folder);
            }
        }
        return $uploaded;
    }

    /**
     * Créer un post
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'space_slug' => 'nullable|string|exists:spaces,slug',
            'type' => 'nullable|in:post,debate',
            'content' => 'nullable|string|max:5000',
            'media' => 'nullable|array',
            'media.*.type' => 'required|in:image,video,audio,pdf,other',
            'media.*.metadata' => 'nullable|json',
            'media.*.url' => 'nullable|string|max:2048',
            'media.*.file' => 'nullable|file|max:20480',
            'style' => 'nullable|string', 
            'is_pinned' => 'boolean',
            'is_featured' => 'boolean',
            'option_a' => 'nullable|string',
            'option_b' => 'nullable|string',
            'option_a_media.*.file' => ['nullable', 'file',  new MaxAudioDuration(120)],
            'option_b_media.*.file' => ['nullable', 'file',  new MaxAudioDuration(120)],
            'option_a_media.*.metadata' => 'nullable|json',
            'option_b_media.*.metadata' => 'nullable|json',
            'option_a_media' => 'nullable|array',
            'option_b_media' => 'nullable|array',
            'end_date' => 'nullable|date|after:now',
            'title' => 'required_if:type,contest|string',
            'description' => 'nullable|string',
            'prize' => 'nullable|string',
            'category' => 'nullable|string',
            'mode' => 'nullable|in:simple,complex,platform',
            'has_jury' => 'nullable',
            'jury_percentage' => 'nullable|integer|min:0|max:100',
            'vote_type' => 'nullable|in:single,multiple',
            'vote_cost' => 'nullable|numeric|min:0',
            'vote_start_at' => 'nullable|date',
            'vote_end_at' => 'nullable|date|after:vote_start_at',
            'cover_media' => 'nullable|array',
            'cover_media.*.file' => 'nullable|file|max:20480',
            'cover_media.*.type' => 'nullable|in:image,video',
            'candidates' => 'nullable|array',
            'candidates.*.name' => 'required_with:candidates|string',
            'candidates.*.description' => 'nullable|string',
            'candidates.*.photos' => 'nullable|array',
            'candidates.*.photos.*' => 'nullable|file|max:20480|image',
            'candidates.*.video' => 'nullable|file|max:20480|mimetypes:video/mp4,video/webm,video/quicktime',
            'submission_docs' => 'nullable|array',
            'submission_docs.*.type' => 'required_with:submission_docs|string',
            'submission_docs.*.label' => 'required_with:submission_docs|string',
            'submission_docs.*.required' => 'nullable|boolean',
            'submission_docs.*.max_files' => 'nullable|integer|min:1',
            'submission_start' => 'nullable|date',
            'submission_end' => 'nullable|date|after:submission_start',
            'max_applications' => 'nullable|integer|min:1',
            'platform_min' => 'nullable|integer|min:2',
            'platform_max' => 'nullable|integer|min:3',
            'rounds' => 'nullable|array',
            'rounds.*.number' => 'required_with:rounds|integer',
            'rounds.*.qualifiers' => 'required_with:rounds|integer|min:1',
            'rounds.*.start_date' => 'required_with:rounds|date',
            'rounds.*.end_date' => 'required_with:rounds|date|after:rounds.*.start_date',
            'rounds.*.jury_active' => 'nullable|boolean',
            'jury_members' => 'nullable|array',
            'jury_members.*.user_id' => 'nullable|integer|exists:users,id',
            'jury_members.*.name' => 'nullable|string|max:255|required_without:jury_members.*.user_id',
            'jury_members.*.username' => 'nullable|string|max:255',
            'jury_members.*.avatar_url' => 'nullable|string|max:2048',
            'jury_members.*.photo' => 'nullable|file|max:20480|image',
            'challenge_description' => 'required_if:type,challenge|string',
            'max_participants' => 'nullable|integer|min:1',
            'votes_to_win' => 'nullable|integer|min:1',
            'topic' => 'required_if:type,debate|string',
            'for_argument' => 'required_if:type,debate|string',
            'against_argument' => 'required_if:type,debate|string',
        ]);

        // Les défis, duels, concours et tournois se créent désormais via POST /api/competitions
        if (in_array($request->input('type'), ['duel', 'contest', 'challenge'], true)) {
            return response()->json(['success' => false, 'message' => 'Utilise POST /api/competitions pour créer un défi, un duel, un concours ou un tournoi.'], 422);
        }

        $authorableType = User::class;
        $authorableId = $user->id;

        if (!empty($validated['space_slug'])) {
            $space = Space::where('slug', $validated['space_slug'])->first();
            if ($space) {
                $authorableType = Space::class;
                $authorableId = $space->id;

                if (!$space->canPost($user)) {
                    return response()->json(['success' => false, 'message' => __('You cannot post in this space.')], 403);
                }
            } else {
                return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
            }
        } elseif ($validated['type'] === 'contest') {
            return response()->json(['success' => false, 'message' => 'Les concours doivent être créés dans un espace.'], 422);
        }

        DB::beginTransaction();
        try {
            // Utilisation de la fonction factorisée pour traiter les médias du post
            $uploadedMedia = $this->processMediaArray($validated['media'] ?? [], 'posts');

            $post = Post::create([
                'authorable_type' => $authorableType,
                'authorable_id' => $authorableId,
                'user_id' => $user->id,
                'type' => $validated['type'] ?? 'post',
                'slug' => $this->generateSlug($validated['type'] ?? 'post', $user),
                'content' => $validated['content'] ?? null,
                'media' => $uploadedMedia,
                 'style' => isset($validated['style'])
                    ? json_decode($validated['style'], true)   // ⭐ AJOUT
                    : null,
                'is_pinned' => $validated['is_pinned'] ?? false,
                'is_featured' => $validated['is_featured'] ?? false,
                'published_at' => now(),
                'status' => 'published',
            ]);

            $this->createTypeData($post, $validated);

            DB::commit();

            app(\App\Services\Social\SocialHooks::class)->postCreated($post);

            return response()->json([
                'success' => true,
                'message' => __('Post created successfully.'),
                'data' => $post->load(['author', 'duel', 'contest', 'challenge', 'debate']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Post creation failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Impossible de publier pour le moment. Réessaie.',
            ], 500);
        }
    }

    /**
     * Générer un slug unique
     */
    private function generateSlug(string $type, User $user): string
    {
        $baseSlug = $type . '-' . Str::slug($user->username) . '-' . now()->timestamp;
        $slug = $baseSlug;
        $counter = 1;

        while (Post::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Créer les données spécifiques au type
     */
    private function createTypeData(Post $post, array $data): void
    {
        switch ($post->type) {
            case 'duel':
                $this->createDuelData($post, $data);
                break;

            case 'contest':
                $this->createContestData($post, $data);
                break;

            case 'debate':
                $post->debate()->create([
                    'topic' => $data['topic'],
                    'for_argument' => $data['for_argument'],
                    'against_argument' => $data['against_argument'],
                    'end_date' => $data['end_date'] ?? null,
                ]);
                break;

            case 'challenge':
                $post->challenge()->create([
                    'description' => $data['challenge_description'],
                    'max_participants' => $data['max_participants'] ?? null,
                    'votes_to_win' => $data['votes_to_win'] ?? 10,
                    'end_date' => $data['end_date'] ?? null,
                ]);
                break;
        }
    }

    /**
     * Créer les données d'un duel
     */
    private function createDuelData(Post $post, array $data): void
    {
        $optionAMedia = $this->processMediaArray($data['option_a_media'] ?? [], 'duels');
        $optionBMedia = $this->processMediaArray($data['option_b_media'] ?? [], 'duels');

        $post->duel()->create([
            'content' => $data['content'] ?? null,
            'option_a' => $data['option_a'],
            'option_b' => $data['option_b'],
            'option_a_media' => $optionAMedia,
            'option_b_media' => $optionBMedia,
            'end_date' => $data['end_date'] ?? null,
        ]);
    }

    /**
     * Créer les données d'un concours
     */
    private function createContestData(Post $post, array $data): void
    {
        $spaceSlug = null;
        if ($post->authorable_type === Space::class) {
            $space = Space::find($post->authorable_id);
            if ($space) $spaceSlug = $space->slug;
        }

        $coverMedia = [];
        if (!empty($data['cover_media'])) {
            foreach ($data['cover_media'] as $mediaItem) {
                if (isset($mediaItem['file']) && $mediaItem['file'] instanceof \Illuminate\Http\UploadedFile) {
                    $url = $this->uploadFile($mediaItem['file'], 'contests');
                    $coverMedia[] = [
                        'type' => $mediaItem['type'] ?? 'image',
                        'url' => $url,
                    ];
                } elseif (isset($mediaItem['url'])) {
                    $coverMedia[] = [
                        'type' => $mediaItem['type'] ?? 'image',
                        'url' => $mediaItem['url'],
                    ];
                }
            }
        }

        $mode = $data['mode'] ?? 'simple';

        $candidatesData = [];
        if (!empty($data['candidates']) && $mode === 'complex') {
            $candidatesData = $this->processCandidates($data['candidates']);
        }

        $juryMembers = [];
        if (!empty($data['jury_members']) && (bool) ($data['has_jury'] ?? false)) {
            $juryMembers = $this->processJuryMembers($data['jury_members']);
        }

        $rounds = [];
        if (!empty($data['rounds'])) {
            $rounds = array_map(function($round) {
                return [
                    'number' => $round['number'] ?? 0,
                    'qualifiers' => $round['qualifiers'] ?? 0,
                    'startDate' => $round['start_date'] ?? null,
                    'endDate' => $round['end_date'] ?? null,
                    'juryActive' => $round['jury_active'] ?? false,
                ];
            }, $data['rounds']);
        }

        $submissionDocs = [];
        if (!empty($data['submission_docs']) && in_array($mode, ['simple', 'platform'], true)) {
            $submissionDocs = array_map(function($doc) {
                return [
                    'type' => $doc['type'] ?? 'photo',
                    'label' => $doc['label'] ?? '',
                    'required' => $doc['required'] ?? false,
                    'maxFiles' => $doc['max_files'] ?? 1,
                ];
            }, $data['submission_docs']);
        }

        $post->contest()->create([
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? null,
            'prize' => $data['prize'] ?? null,
            'category' => $data['category'] ?? null,
            'mode' => $mode,
            'space_slug' => $spaceSlug,
            'max_participants' => $data['platform_max'] ?? null,
            'submission_docs' => $submissionDocs,
            'submission_start' => $data['submission_start'] ?? null,
            'submission_end' => $data['submission_end'] ?? null,
            'max_applications' => $data['max_applications'] ?? null,
            'vote_start_at' => $data['vote_start_at'] ?? null,
            'vote_end_at' => $data['vote_end_at'] ?? null,
            'has_jury' => $data['has_jury'] ?? false,
            'jury_percentage' => intval($data['jury_percentage'] ?? 0),
            'jury_members' => $juryMembers,
            'vote_type' => $data['vote_type'] ?? 'single',
            'vote_cost' => floatval($data['vote_cost'] ?? 0),
            'candidates' => $candidatesData,
            'rounds' => $rounds,
            'cover_media' => $coverMedia,
        ]);

        if (!empty($rounds)) {
            foreach ($rounds as $round) {
                $post->contestRounds()->create([
                    'round_number' => $round['number'],
                    'qualifiers' => $round['qualifiers'],
                    'start_date' => $round['startDate'],
                    'end_date' => $round['endDate'],
                    'jury_active' => $round['juryActive'],
                ]);
            }
        }
    }

    /**
     * Traiter les médias d'un tableau
     */
    private function processMediaArray(array $mediaItems, string $folder): array
    {
        $processed = [];

        foreach ($mediaItems as $mediaItem) {
            $file = $mediaItem['file'] ?? null;

            // ⭐ Récupérer le type envoyé par le client en priorité
            $mediaType = $mediaItem['type'] ?? null;
            if ($file instanceof \Illuminate\Http\UploadedFile && $mediaType === null) {
                $extension = strtolower($file->getClientOriginalExtension());

                if ($extension === 'webm') {
                    // Analyse poussée du conteneur WebM
                    $getID3 = new \getID3();
                    $fileInfo = $getID3->analyze($file->getRealPath());

                    // Si le fichier contient un flux vidéo (video stream), c'est une vidéo
                    if (isset($fileInfo['video']) && !empty($fileInfo['video']['resolution_x'])) {
                        $mediaType = 'video';
                    } else {
                        // S'il n'y a pas de flux vidéo, c'est uniquement de l'audio
                        $mediaType = 'audio';
                    }
                } else {
                    // Pour les autres formats classiques (mp3, mp4, jpg...)
                    $mimeType = $file->getMimeType();
                    if (str_starts_with($mimeType, 'audio/') || in_array($extension, ['mp3','m4a','wav', 'ogg'])) {
                        $mediaType = 'audio';
                    } elseif (str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mov'])) {
                        $mediaType = 'video';
                    } else {
                        $mediaType = 'image';
                    }
                }
            }

            $mediaData = [
                'type' => $mediaType,
            ];

            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $mediaData['url'] = $this->uploadFile($file, $folder);
            } elseif (isset($mediaItem['url'])) {
                $mediaData['url'] = $mediaItem['url'];
            } else {
                continue;
            }

            // Métadonnées pour l'audio (peaks + duration)
            if ($mediaType === 'audio') {
                $peaks = [];
                $duration = null;

                // Récupérer la valeur de 'metadata' (qui peut être une string JSON ou un array selon la façon dont Laravel le parse)
                $rawMetadata = $mediaItem['metadata'] ?? null;

                $metadata = [];
                if (!empty($rawMetadata)) {
                    $metadata = is_string($rawMetadata) ? json_decode($rawMetadata, true) : $rawMetadata;
                }

                // Extraire les peaks en toute sécurité
                if (isset($metadata['peaks'])) {
                    $rawPeaks = $metadata['peaks'];
                    $peaks = is_string($rawPeaks) ? json_decode($rawPeaks, true) : $rawPeaks;
                }

                // Extraire la durée
                if (isset($metadata['duration'])) {
                    $duration = (float) $metadata['duration'];
                }

                // Logger pour vérifier proprement ce qui est extrait
                logger('Audio metadata processed:', ['peaks_count' => count($peaks), 'duration' => $duration]);

                // Structuration finale pour ta base de données ou ton stockage
                $mediaData['metadata'] = [
                    'peaks' => $peaks,
                    'duration' => $duration,
                ];
            }

            $processed[] = $mediaData;
        }

        return $processed;
    }

    /**
     * Traiter les candidats avec leurs médias
     */
    private function processCandidates(array $candidates): array
    {
        $processed = [];
        foreach ($candidates as $candidate) {
            $photos = [];
            if (!empty($candidate['photos'])) {
                foreach ($candidate['photos'] as $photo) {
                    if ($photo instanceof \Illuminate\Http\UploadedFile) {
                        $photos[] = $this->uploadFile($photo, 'candidates');
                    } else {
                        $photos[] = $photo;
                    }
                }
            }

            $video = null;
            if (!empty($candidate['video']) && $candidate['video'] instanceof \Illuminate\Http\UploadedFile) {
                $video = $this->uploadFile($candidate['video'], 'candidates');
            } elseif (!empty($candidate['video'])) {
                $video = $candidate['video'];
            }

            $processed[] = [
                'name' => $candidate['name'] ?? '',
                'description' => $candidate['description'] ?? '',
                'photos' => $photos,
                'video' => $video,
            ];
        }
        return $processed;
    }

    /**
     * Traiter les membres du jury avec leurs photos
     */
    private function processJuryMembers(array $juryMembers): array
    {
        $processed = [];

        foreach ($juryMembers as $member) {
            if (!empty($member['user_id'])) {
                /*
                 * Juré sélectionné depuis la recherche.
                 *
                 * On ne fait pas confiance aux informations textuelles envoyées
                 * par le navigateur : l'ID est vérifié dans la base et le snapshot
                 * est créé à partir de l'utilisateur réellement trouvé.
                 */
                $user = User::query()->find((int) $member['user_id']);

                if (!$user) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'jury_members' => [
                            "Le membre du jury sélectionné n'existe plus.",
                        ],
                    ]);
                }

                $snapshotName = trim((string) ($user->name ?? ''));

                if ($snapshotName === '') {
                    $snapshotName = trim(
                        (string) ($user->first_name ?? '')
                        . ' '
                        . (string) ($user->last_name ?? '')
                    );
                }

                if ($snapshotName === '') {
                    $snapshotName = (string) ($user->username ?? 'Membre du jury');
                }

                $processed[] = [
                    'user_id' => (int) $user->id,
                    'name' => $snapshotName.' '.$user->last_name,
                    //get original colo,n data
                    'avatar_path' => $user->getRawOrigina('avatar'),
                    'photo' => null,
                    'source' => 'registered_user',
                ];

                continue;
            }

            // Juré ajouté manuellement.
            $photo = null;

            if (
                !empty($member['photo'])
                && $member['photo'] instanceof \Illuminate\Http\UploadedFile
            ) {
                $photo = $this->uploadFile($member['photo'], 'jury');
            }

            $processed[] = [
                'user_id' => null,
                'name' => trim((string) ($member['name'] ?? '')),
                'username' => null,
                'avatar_url' => null,
                'photo' => $photo,
                'source' => 'manual',
            ];
        }

        return $processed;
    }

    /**
     * Trouver un post par son ID
     */
    private function findPostById(int $id): ?Post
    {
        return Post::with([
            'author',
            'media',
            'duel',
            'contest',
            'challenge',
            'debate',
            'comments' => function($q) {
                $q->with(['author', 'replies' => function($q2) {
                    $q2->with(['author', 'replies'])->orderBy('created_at', 'asc');
                }])->whereNull('parent_id')->orderBy('created_at', 'desc');
            }
        ])
        ->withCount(['likes', 'comments', 'shares', 'bookmarks'])
        ->find($id);
    }

    /**
     * ⭐ Trouver un post par son SLUG
     */
    private function findPostBySlug(string $slug): ?Post
    {
        return Post::with([
            'author',
            'media',
            'duel',
            'contest',
            'challenge',
            'debate',
        ])
        ->withCount(['likes', 'comments', 'shares', 'bookmarks'])
        ->where('slug', $slug)
        ->first();
    }

    /**
     * ⭐ Déterminer si l'utilisateur peut défier/rejoindre le challenge
     */
    private function userCanChallenge(Post $post, ?User $user): bool
    {
        if (!$user) {
            return false;
        }

        // Si le post n'est pas un challenge, retourner false
        if ($post->type !== 'challenge') {
            return false;
        }

        // Si le challenge est terminé
        if ($post->challenge && $post->challenge->isEnded()) {
            return false;
        }

        // L'utilisateur ne peut pas défier son propre challenge
        if ($post->user_id === $user->id) {
            return false;
        }

        // Si c'est un espace, vérifier que l'utilisateur a rejoint l'espace
        if ($post->authorable_type === 'App\\Models\\Space') {
            $space = Space::find($post->authorable_id);
            if ($space && !$space->isMember($user)) {
                return false;
            }
        }

        // Vérifier si l'utilisateur a déjà participé
        $hasJoined = \App\Models\ChallengeParticipant::where('challenge_id', $post->challenge->id)
            ->where('user_id', $user->id)
            ->exists();

        // Autoriser plusieurs participations (retourner true même si déjà participé)
        // Pour les défis, on autorise l'utilisateur à défier même s'il a déjà participé
        return true;
    }

    // ═══════════════════════════════════════════════════════════════
    // ║  MÉTHODES AVEC SLUG (UNIQUEMENT POUR LE CHARGEMENT)
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ Afficher un post par SLUG avec les droits de défi
     */
    public function showBySlug(Request $request, string $slug): JsonResponse
    {
        try {
            Log::info('🔍 showBySlug called with slug: ' . $slug);

            $post = $this->findPostBySlug($slug);

            if (!$post) {
                Log::warning('❌ Post not found for slug: ' . $slug);
                return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
            }

            Log::info('✅ Post found: ' . $post->id);

            $user = $request->user();

            // Ajouter les informations utilisateur
            if ($user) {
                $post->is_liked_by_user = $post->likes()->where('user_id', $user->id)->exists();
                $post->is_bookmarked_by_user = $post->bookmarks()->where('user_id', $user->id)->exists();
                $post->is_following_user = $post->is_following_user;

                if ($post->type === 'duel' && $post->duel) {
                    $post->voted_option = PostVote::where('post_id', $post->id)
                        ->where('user_id', $user->id)
                        ->where('voteable_type', 'duel_option')
                        ->where('voteable_id', $post->duel->id)
                        ->value('value');
                }

                // ⭐ Ajouter la propriété can_user_challenge
                $post->can_user_challenge = $this->userCanChallenge($post, $user);

                // ⭐ Ajouter la propriété has_user_joined (pour les challenges)
                if ($post->type === 'challenge' && $post->challenge) {
                    $post->is_joined_by_user = \App\Models\ChallengeParticipant::where('challenge_id', $post->challenge->id)
                        ->where('user_id', $user->id)
                        ->exists();
                }
            } else {
                // Utilisateur non connecté
                $post->is_liked_by_user = false;
                $post->is_bookmarked_by_user = false;
                $post->is_following_user = false;
                $post->can_user_challenge = false;
                $post->is_joined_by_user = false;
            }

            return response()->json([
                'success' => true,
                'data' => $post,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ showBySlug error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'slug' => $slug
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error loading post: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ║  TOUTES LES AUTRES MÉTHODES UTILISENT L'ID
    // ═══════════════════════════════════════════════════════════════

    /**
     * Afficher un post par ID
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $post = $this->findPostById($id);

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $user = $request->user();
        if ($user) {
            $post->is_liked_by_user = $post->likes()->where('user_id', $user->id)->exists();
            $post->is_bookmarked_by_user = $post->bookmarks()->where('user_id', $user->id)->exists();

            if ($post->type === 'duel' && $post->duel) {
                $post->voted_option = PostVote::where('post_id', $post->id)
                    ->where('user_id', $user->id)
                    ->where('voteable_type', 'duel_option')
                    ->where('voteable_id', $post->duel->id)
                    ->value('value');
            }
        }

        return response()->json([
            'success' => true,
            'data' => $post,
        ]);
    }

    /**
     * Mettre à jour un post par ID
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $post = Post::find($id);
        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $user = $request->user();
        if (!$post->canEdit($user)) {
            return response()->json(['success' => false, 'message' => __('You cannot edit this post.')], 403);
        }

        $validated = $request->validate([
            'content' => 'nullable|string|max:5000',
            'media' => 'nullable|array',
            'is_pinned' => 'boolean',
            'is_featured' => 'boolean',
        ]);

        $post->update($validated);

        return response()->json([
            'success' => true,
            'message' => __('Post updated.'),
            'data' => $post,
        ]);
    }

    /**
     * Supprimer un post par ID
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $post = Post::find($id);
        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $user = $request->user();
        if (!$post->canDelete($user)) {
            return response()->json(['success' => false, 'message' => __('You cannot delete this post.')], 403);
        }

        DB::beginTransaction();
        try {
            $post->duel()->delete();
            $post->contest()->delete();
            $post->challenge()->delete();
            $post->debate()->delete();
            $post->media()->delete();
            $post->likes()->delete();
            $post->votes()->delete();
            $post->allComments()->delete();
            $post->contestRounds()->delete();
            $post->views()->delete();
            $post->shares()->delete();
            $post->bookmarks()->delete();
            $post->interactions()->delete();
            $post->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('Post deleted.'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete post: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Liker un post par ID
     */
    public function like(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $user = $request->user();
        $userId = $user->id;

        $existingLike = PostLike::where('post_id', $postId)
            ->where('user_id', $userId)
            ->first();

        if ($existingLike) {
            $existingLike->delete();
            $liked = false;
        } else {
            PostLike::create([
                'post_id' => $postId,
                'user_id' => $userId,
            ]);
            $liked = true;

            UserInteraction::record($userId, $postId, 'like');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'liked' => $liked,
                'likes_count' => $post->likes()->count(),
            ]
        ]);
    }

    /**
     * Marquer un post comme vu par ID
     */
    public function markAsViewed(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $user = $request->user();
        $duration = $request->input('duration', 5);

        $view = PostView::updateOrCreate(
            [
                'user_id' => $user->id,
                'post_id' => $postId,
            ],
            [
                'viewed_at' => now(),
                'duration' => $duration,
            ]
        );

        UserInteraction::record($user->id, $postId, 'view');

        return response()->json([
            'success' => true,
            'message' => __('Post marked as viewed'),
        ]);
    }

    /**
     * Commenter un post par ID
     */
    public function comment(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'parent_id' => 'nullable|exists:post_comments,id',
        ]);

        if (isset($validated['parent_id'])) {
            $parent = PostComment::find($validated['parent_id']);
            if (!$parent || $parent->post_id !== $postId) {
                return response()->json(['success' => false, 'message' => __('Parent comment not found in this post.')], 422);
            }
        }

        $user = $request->user();
        $comment = PostComment::create([
            'post_id' => $postId,
            'user_id' => $user->id,
            'content' => $validated['content'],
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

        UserInteraction::record($user->id, $postId, 'comment');
        app(\App\Services\Social\SocialHooks::class)->commented($comment);

        return response()->json([
            'success' => true,
            'message' => __('Comment added.'),
            'data' => $comment->load('author', 'replies'),
        ], 201);
    }

    /**
     * Récupérer les commentaires d'un post par ID
     */
    public function getComments(
        Request $request,
        int $postId
    ): JsonResponse {
        if (!Post::whereKey($postId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => __('Post not found.'),
            ], 404);
        }

        $userId = $request->user()?->id;

        $likedByUser = function ($query) use ($userId) {
            if ($userId) {
                $query->where('user_id', $userId);
            } else {
                $query->whereRaw('1 = 0');
            }
        };

        $comments = PostComment::query()
            ->where('post_id', $postId)
            ->whereNull('parent_id')
            ->with([
                'author:id,username,first_name,last_name,gender,avatar,verified_at',
                'replies' => function ($replyQuery) use ($likedByUser) {
                    $replyQuery
                        ->with([
                            'author:id,username,first_name,last_name,gender,avatar,verified_at',
                            'replies' => function ($subReplyQuery) use ($likedByUser) {
                                $subReplyQuery
                                    ->with(
                                        'author:id,username,first_name,last_name,gender,avatar,verified_at'
                                    )
                                    ->withCount('likes')
                                    ->withExists([
                                        'likes as is_liked_by_user' => $likedByUser,
                                    ])
                                    ->oldest();
                            },
                        ])
                        ->withCount(['likes', 'replies'])
                        ->withExists([
                            'likes as is_liked_by_user' => $likedByUser,
                        ])
                        ->oldest();
                },
            ])
            ->withCount(['likes', 'replies'])
            ->withExists([
                'likes as is_liked_by_user' => $likedByUser,
            ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $comments,
        ]);
    }

    /**
     * Bookmark par ID
     */
    public function toggleBookmark(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        $post = Post::find($postId);

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $bookmark = \App\Models\PostBookmark::where('post_id', $postId)
            ->where('user_id', $user->id)
            ->first();

        if ($bookmark) {
            $bookmark->delete();
            $bookmarked = false;
        } else {
            \App\Models\PostBookmark::create([
                'post_id' => $postId,
                'user_id' => $user->id,
            ]);
            $bookmarked = true;
            UserInteraction::record($user->id, $postId, 'bookmark');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'bookmarked' => $bookmarked,
                'bookmarks_count' => $post->bookmarks()->count(),
            ]
        ]);
    }

    /**
     * Share par ID
     */
    public function share(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        $post = Post::find($postId);

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $platform = $request->input('platform', 'web');

        $share = \App\Models\PostShare::create([
            'post_id' => $postId,
            'user_id' => $user->id,
            'platform' => $platform,
        ]);

        UserInteraction::record($user->id, $postId, 'share');

        return response()->json([
            'success' => true,
            'data' => [
                'shared' => true,
                'shares_count' => $post->shares()->count(),
            ]
        ]);
    }

    /**
     * Report par ID
     */
    public function report(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        $post = Post::find($postId);

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        if (\App\Models\PostReport::hasReported($user->id, $postId)) {
            return response()->json([
                'success' => false,
                'message' => __('You have already reported this post.'),
            ], 422);
        }

        $report = \App\Models\PostReport::create([
            'post_id' => $postId,
            'user_id' => $user->id,
            'reason' => $validated['reason'],
            'description' => $validated['description'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Post reported successfully.'),
            'data' => $report,
        ]);
    }

    /**
     * Reply to a comment
     */
    public function replyComment(Request $request, int $commentId): JsonResponse
    {
        $parent = PostComment::find($commentId);
        if (!$parent) {
            return response()->json(['success' => false, 'message' => __('Comment not found.')], 404);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $user = $request->user();
        $comment = PostComment::create([
            'post_id' => $parent->post_id,
            'user_id' => $user->id,
            'content' => $validated['content'],
            'parent_id' => $commentId,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Reply added.'),
            'data' => $comment->load('author'),
        ], 201);
    }

    /**
     * Liker un commentaire
     */
    public function likeComment(Request $request, int $commentId): JsonResponse
    {
        $comment = PostComment::find($commentId);
        if (!$comment) {
            return response()->json(['success' => false, 'message' => __('Comment not found.')], 404);
        }

        $user = $request->user();
        $userId = $user->id;

        $existingLike = \App\Models\CommentLike::where('comment_id', $commentId)
            ->where('user_id', $userId)
            ->first();

        if ($existingLike) {
            $existingLike->delete();
            $liked = false;
        } else {
            \App\Models\CommentLike::create([
                'comment_id' => $commentId,
                'user_id' => $userId,
            ]);
            $liked = true;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'liked' => $liked,
                'likes_count' => $comment->likes()->count(),
            ]
        ]);
    }

    /**
     * Supprimer un commentaire
     */
    public function deleteComment(Request $request, int $commentId): JsonResponse
    {
        $comment = PostComment::find($commentId);
        if (!$comment) {
            return response()->json(['success' => false, 'message' => __('Comment not found.')], 404);
        }

        $user = $request->user();
        $post = Post::find($comment->post_id);
        $isAuthor = $comment->user_id === $user->id;
        $isPostAuthor = $post && $post->user_id === $user->id;
        $isSpaceManager = false;

        if ($post && $post->authorable_type === 'App\Models\Space') {
            $space = Space::find($post->authorable_id);
            if ($space) $isSpaceManager = $space->isManager($user);
        }

        if (!$isAuthor && !$isPostAuthor && !$isSpaceManager) {
            return response()->json(['success' => false, 'message' => __('You cannot delete this comment.')], 403);
        }

        $comment->replies()->delete();
        $comment->likes()->delete();
        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => __('Comment deleted.'),
        ]);
    }
}