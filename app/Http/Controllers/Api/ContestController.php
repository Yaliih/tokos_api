<?php
// app/Http/Controllers/Api/ContestController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\ContestCandidate;
use App\Models\ContestParticipant;
use App\Models\CandidateVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContestController extends Controller
{
    /**
     * Récupérer les candidats d'un concours
     * Utilise POST.ID
     */
    public function getCandidates(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post || $post->type !== 'contest') {
            return response()->json(['success' => false, 'message' => 'Contest not found.'], 404);
        }

        $contest = $post->contest;
        if (!$contest) {
            return response()->json(['success' => false, 'message' => 'Contest data not found.'], 404);
        }

        $user = $request->user();

        $candidates = ContestCandidate::where('contest_id', $contest->id)
            ->orderBy('display_order')
            ->orderBy('total_votes', 'desc')
            ->get()
            ->map(function ($candidate) use ($user) {
                return [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'description' => $candidate->description,
                    'photos' => $candidate->photos,
                    'photos_urls' => $candidate->photos_urls,
                    'video' => $candidate->video,
                    'video_url' => $candidate->video_url,
                    'total_votes' => $candidate->total_votes,
                    'votes_count' => $candidate->votes_count,
                    'display_order' => $candidate->display_order,
                    'status' => $candidate->status,
                    'has_user_voted' => $user ? $candidate->hasUserVoted($user->id) : false,
                    'created_at' => $candidate->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $candidates,
        ]);
    }

    /**
     * Récupérer les détails d'un candidat
     * Utilise CANDIDATE.ID
     */
    public function getCandidate(Request $request, int $candidateId): JsonResponse
    {
        $candidate = ContestCandidate::with(['post', 'contest'])
            ->find($candidateId);

        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Candidate not found.'], 404);
        }

        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'description' => $candidate->description,
                'photos' => $candidate->photos,
                'photos_urls' => $candidate->photos_urls,
                'video' => $candidate->video,
                'video_url' => $candidate->video_url,
                'total_votes' => $candidate->total_votes,
                'votes_count' => $candidate->votes_count,
                'display_order' => $candidate->display_order,
                'status' => $candidate->status,
                'has_user_voted' => $user ? $candidate->hasUserVoted($user->id) : false,
                'post' => [
                    'id' => $candidate->post->id,
                    'slug' => $candidate->post->slug,
                    'title' => $candidate->post->contest?->title,
                ],
                'contest' => [
                    'id' => $candidate->contest->id,
                    'title' => $candidate->contest->title,
                    'status' => $candidate->contest->status_text,
                ],
                'created_at' => $candidate->created_at,
                'updated_at' => $candidate->updated_at,
            ],
        ]);
    }

    /**
     * Récupérer les participants d'un concours
     * Utilise POST.ID
     */
    public function getParticipants(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post || $post->type !== 'contest') {
            return response()->json(['success' => false, 'message' => 'Contest not found.'], 404);
        }

        $contest = $post->contest;
        if (!$contest) {
            return response()->json(['success' => false, 'message' => 'Contest data not found.'], 404);
        }

        $participants = ContestParticipant::with(['user'])
            ->where('post_id', $post->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($participant) {
                return [
                    'id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'submissions' => $participant->submissions,
                    'status' => $participant->status,
                    'score' => $participant->score,
                    'user' => $participant->user ? [
                        'id' => $participant->user->id,
                        'username' => $participant->user->username,
                        'first_name' => $participant->user->first_name,
                        'last_name' => $participant->user->last_name,
                        'avatar' => $participant->user->avatar,
                        'is_verified' => $participant->user->is_verified ?? false,
                    ] : null,
                    'created_at' => $participant->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $participants,
        ]);
    }

    /**
     * Postuler à un concours
     * Utilise POST.ID
     */
    public function apply(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();

        $post = Post::find($postId);
        if (!$post || $post->type !== 'contest') {
            return response()->json(['success' => false, 'message' => 'Contest not found.'], 404);
        }

        $contest = $post->contest;
        if (!$contest) {
            return response()->json(['success' => false, 'message' => 'Contest data not found.'], 404);
        }

        // Vérifier si le concours accepte les candidatures
        if ($contest->status_text !== 'submission' && $contest->status_text !== 'live') {
            return response()->json([
                'success' => false,
                'message' => 'Les candidatures ne sont pas ouvertes pour ce concours.',
            ], 422);
        }

        // Vérifier si l'utilisateur a déjà postulé
        $existing = ContestParticipant::where('post_id', $post->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà postulé à ce concours.',
            ], 422);
        }

        // Vérifier le nombre max de participants
        if ($contest->max_participants) {
            $count = ContestParticipant::where('post_id', $post->id)->count();
            if ($count >= $contest->max_participants) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le concours est complet.',
                ], 422);
            }
        }

        $validated = $request->validate([
            'submissions' => ['nullable', 'array'],
            'submissions.*.type' => ['required', 'string', 'max:50'],
            'submissions.*.label' => ['nullable', 'string', 'max:255'],

            /*
             * Un champ texte contient "value".
             * Un champ fichier contient "files" et n'a donc pas besoin de "value".
             */
            'submissions.*.value' => [
                'nullable',
                'string',
                'required_without:submissions.*.files',
            ],
            'submissions.*.files' => [
                'nullable',
                'array',
                'required_without:submissions.*.value',
            ],
            'submissions.*.files.*' => [
                'file',
                'max:51200',
                'mimes:jpg,jpeg,png,webp,gif,mp4,mov,avi,webm,pdf,ppt,pptx',
            ],
        ], [
            'submissions.*.value.required_without' =>
                'Ce champ doit contenir une réponse ou au moins un fichier.',
            'submissions.*.files.required_without' =>
                'Ce champ doit contenir au moins un fichier ou une réponse.',
            'submissions.*.files.*.file' =>
                'Le fichier envoyé est invalide.',
            'submissions.*.files.*.max' =>
                'Chaque fichier ne peut pas dépasser 50 Mo.',
            'submissions.*.files.*.mimes' =>
                'Ce format de fichier n’est pas autorisé.',
        ]);

        $storedSubmissions = [];

        foreach ($validated['submissions'] ?? [] as $index => $submission) {
            $storedSubmission = [
                'type' => $submission['type'],
                'label' => $submission['label'] ?? null,
            ];

            if (array_key_exists('value', $submission)) {
                $storedSubmission['value'] = $submission['value'];
            }

            /*
             * On récupère les vrais UploadedFile depuis la Request.
             * Ils ne doivent pas être enregistrés directement dans le JSON.
             */
            $uploadedFiles = $request->file("submissions.$index.files", []);

            if (!is_array($uploadedFiles)) {
                $uploadedFiles = [$uploadedFiles];
            }

            $storedFiles = [];

            foreach ($uploadedFiles as $uploadedFile) {
                if (!$uploadedFile || !$uploadedFile->isValid()) {
                    continue;
                }

                $path = $uploadedFile->store(
                    "contest-submissions/{$post->id}/{$user->id}",
                    'public'
                );

                $storedFiles[] = [
                    'path' => $path,
                    'original_name' => $uploadedFile->getClientOriginalName(),
                    'mime_type' => $uploadedFile->getMimeType(),
                    'size' => $uploadedFile->getSize(),
                ];
            }

            if (!empty($storedFiles)) {
                $storedSubmission['files'] = $storedFiles;
            }

            $storedSubmissions[] = $storedSubmission;
        }

        $participant = ContestParticipant::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'submissions' => $storedSubmissions,
            'status' => 'pending',
            'score' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Candidature envoyée avec succès !',
            'data' => $participant->load('user'),
        ], 201);
    }

    /**
     * Voter pour un candidat
     * Utilise CANDIDATE.ID
     */
    public function voteCandidate(Request $request, int $candidateId): JsonResponse
    {
        $user = $request->user();

        $candidate = ContestCandidate::with(['contest'])
            ->find($candidateId);

        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Candidate not found.'], 404);
        }

        $contest = $candidate->contest;
        if (!$contest) {
            return response()->json(['success' => false, 'message' => 'Contest not found.'], 404);
        }

        // Vérifier si le vote est ouvert
        if ($contest->status_text !== 'live') {
            return response()->json([
                'success' => false,
                'message' => 'Le vote n\'est pas ouvert pour ce concours.',
            ], 422);
        }

        // Vérifier si l'utilisateur a déjà voté pour ce candidat
        if ($candidate->hasUserVoted($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà voté pour ce candidat.',
            ], 422);
        }

        // Vérifier si l'utilisateur a déjà voté dans ce concours (vote unique)
        if ($contest->vote_type === 'single') {
            $existingVote = CandidateVote::whereHas('candidate', function ($query) use ($contest) {
                $query->where('contest_id', $contest->id);
            })->where('user_id', $user->id)->exists();

            if ($existingVote) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous avez déjà voté dans ce concours.',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            // Créer le vote
            $vote = CandidateVote::createUser($candidate->id, $user->id, 1, $request->ip());

            // Mettre à jour le total des votes du candidat
            $candidate->updateTotalVotes();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vote enregistré avec succès !',
                'data' => [
                    'candidate_id' => $candidate->id,
                    'total_votes' => $candidate->total_votes,
                    'contest_total_votes' => $contest->total_votes_count,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du vote: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupérer les résultats d'un concours
     * Utilise POST.ID
     */
    public function getResults(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post || $post->type !== 'contest') {
            return response()->json(['success' => false, 'message' => 'Contest not found.'], 404);
        }

        $contest = $post->contest;
        if (!$contest) {
            return response()->json(['success' => false, 'message' => 'Contest data not found.'], 404);
        }

        // Vérifier si le concours est terminé
        if ($contest->status_text !== 'ended') {
            return response()->json([
                'success' => false,
                'message' => 'Les résultats ne sont pas encore disponibles.',
            ], 422);
        }

        $results = ContestCandidate::where('contest_id', $contest->id)
            ->orderBy('total_votes', 'desc')
            ->get()
            ->map(function ($candidate, $index) {
                return [
                    'rank' => $index + 1,
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'description' => $candidate->description,
                    'photos_urls' => $candidate->photos_urls,
                    'video_url' => $candidate->video_url,
                    'total_votes' => $candidate->total_votes,
                    'is_winner' => $index === 0,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'contest' => [
                    'id' => $contest->id,
                    'title' => $contest->title,
                    'prize' => $contest->prize,
                ],
                'results' => $results,
                'total_votes' => $contest->total_votes_count,
            ],
        ]);
    }
}