<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContestCandidate;
use App\Models\ContestParticipant;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SpaceContestCandidateController extends Controller
{
    /**
     * Vérifie que le concours appartient bien à l'espace et que l'utilisateur
     * courant est autorisé à le gérer.
     */
    private function context(Request $request, string $slug, int $contestId): array
    {
        $space = Space::query()->where('slug', $slug)->first();

        if (!$space) {
            abort(404, 'Espace introuvable.');
        }

        $user = $request->user();

        if (!$user || !$space->canManage($user)) {
            abort(403, 'Vous ne pouvez pas gérer cet espace.');
        }

        $contest = DB::table('post_contests')
            ->join('posts', 'posts.id', '=', 'post_contests.post_id')
            ->where('post_contests.id', $contestId)
            ->where('posts.authorable_type', Space::class)
            ->where('posts.authorable_id', $space->id)
            ->whereNull('posts.deleted_at')
            ->select([
                'post_contests.*',
                'posts.status as post_status',
            ])
            ->first();

        if (!$contest) {
            abort(404, 'Concours introuvable dans cet espace.');
        }

        return [$space, $contest];
    }

    private function publicFileUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $url = route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&',  $path)));
        return $url;
    }

    private function normalizeMedia($media): array
{
    // Si c'est null ou vide
    if (empty($media)) {
        return [];
    }
    
    // Si c'est déjà un tableau
    if (is_array($media)) {
        $items = $media;
    } 
    // Si c'est une chaîne JSON
    else if (is_string($media)) {
        $items = json_decode($media ?: '[]', true);
        if (!is_array($items)) {
            return [];
        }
    }
    // Autre type
    else {
        return [];
    }
    
    // Normaliser chaque élément
    return array_values(array_map(function ($item) {
        if (is_string($item)) {
            return [
                'type' => 'file',
                'path' => $item,
                'url' => $this->publicFileUrl($item),
            ];
        }
        
        if (!is_array($item)) {
            return $item;
        }
        
        $path = $item['path'] ?? $item['url'] ?? null;
        if ($path) {
            $item['url'] = $this->publicFileUrl($path);
        }
        
        return $item;
    }, $items));
}

    private function normalizeSubmissions(array $submissions): array
    {
        return array_values(array_map(function ($submission) {
            if (!is_array($submission)) {
                return $submission;
            }

            $files = $submission['files'] ?? [];

            if (is_array($files)) {
                $submission['files'] = array_values(array_map(function ($file) {
                    if (is_string($file)) {
                        return [
                            'path' => $file,
                            'url' => $this->publicFileUrl($file),
                            'original_name' => basename($file),
                        ];
                    }

                    if (!is_array($file)) {
                        return $file;
                    }

                    $path = $file['path'] ?? $file['url'] ?? null;
                    $file['url'] = $this->publicFileUrl($path);

                    return $file;
                }, $files));
            }

            return $submission;
        }, $submissions));
    }

    /**
     * Liste des candidats déjà admis au concours.
     */
    public function index(Request $request, string $slug, int $contestId): JsonResponse
    {
        [, $contest] = $this->context($request, $slug, $contestId);

        $candidates = ContestCandidate::with('user')
    ->where('post_id', $contest->post_id)
    ->whereNull('deleted_at')
    ->get()
    ->map(function ($candidate) {
        return [
            'id' => $candidate->id,
            'user_id' => $candidate->user_id,
            'name' => $candidate->name,
            'status' => $candidate->status,
            'first_name' => $candidate->user->first_name,
            'last_name' => $candidate->user->last_name,
            'username' => $candidate->user->username,
            'gender' => $candidate->user->gender,
            'avatar_url' => $candidate->user->avatar_url,
            'votes_count' => $candidate->votes()->sum('value'),
            'media' =>  $this->normalizeMedia($candidate->media ?? null),
            'order' => $candidate->order,
            'score' => (int) $candidate->score,
            'created_at' => $candidate->created_at,
        ];
    });
        return response()->json([
            'success' => true,
            'data' => $candidates,
        ]);
    }

    /**
     * Liste des candidatures reçues.
     */
    public function applications(Request $request, string $slug, int $contestId): JsonResponse
    {
        [, $contest] = $this->context($request, $slug, $contestId);

        $items = ContestParticipant::query()
            ->with([
                'user:id,username,first_name,last_name,gender,avatar',
            ])
            ->where('post_id', $contest->post_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ContestParticipant $participant) {
                $user = $participant->user;
                $submissions = $this->normalizeSubmissions(
                    is_array($participant->submissions) ? $participant->submissions : []
                );

                return [
                    'id' => $participant->id,
                    'post_id' => $participant->post_id,
                    'user_id' => $participant->user_id,
                    'submissions' => $submissions,
                    'status' => $participant->status,
                    'decision_reason' => $participant->decision_reason,
                    'reviewed_at' => $participant->reviewed_at,
                    'reviewed_by' => $participant->reviewed_by,
                    'score' => (int) $participant->score,
                    'created_at' => $participant->created_at,
                    'updated_at' => $participant->updated_at,
                    'name' => $user
                        ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                        : 'Candidat',
                    'user' => $user ? [
                        'id' => $user->id,
                        'username' => $user->username,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'gender' => $user->gender,
                        'avatar' => $user->avatar_url ?? $user->avatar,
                        'avatar_url' => $user->avatar_url ?? $user->avatar,
                    ] : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Accepte une candidature et crée automatiquement le candidat.
     */
    public function approve(
        Request $request,
        string $slug,
        int $contestId,
        int $applicationId
    ): JsonResponse {
        [, $contest] = $this->context($request, $slug, $contestId);

        $participant = ContestParticipant::query()
            ->with('user')
            ->where('id', $applicationId)
            ->where('post_id', $contest->post_id)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'Candidature introuvable pour ce concours.',
            ], 404);
        }

        if ($participant->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Cette candidature est déjà acceptée.',
            ], 422);
        }

        DB::transaction(function () use ($participant, $contest, $request) {
            $participant->update([
                'status' => 'approved',
                'decision_reason' => null,
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
            ]);

            $alreadyCandidate = DB::table('contest_candidates')
                ->where('post_id', $contest->post_id)
                ->where('user_id', $participant->user_id)
                ->whereNull('deleted_at')
                ->exists();

            if ($alreadyCandidate) {
                return;
            }

            $name = $participant->user
                ? trim(
                    ($participant->user->first_name ?? '') . ' ' .
                    ($participant->user->last_name ?? '')
                )
                : 'Candidat';

            $media = [];

            foreach ((array) $participant->submissions as $submission) {
                foreach ((array) ($submission['files'] ?? []) as $file) {
                    if (!is_array($file)) {
                        continue;
                    }

                    $mime = (string) ($file['mime_type'] ?? '');

                    if (!str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/')) {
                        continue;
                    }

                    $media[] = [
                        'type' => str_starts_with($mime, 'video/') ? 'video' : 'image',
                        'path' => $file['path'] ?? null,
                        'url' => $this->publicFileUrl($file['path'] ?? $file['url'] ?? null),
                        'mime_type' => $mime,
                        'original_name' => $file['original_name'] ?? null,
                    ];
                }
            }

            $nextOrder = ((int) DB::table('contest_candidates')
                ->where('post_id', $contest->post_id)
                ->max('order')) + 1;

            DB::table('contest_candidates')->insert([
                'post_id' => $contest->post_id,
                'user_id' => $participant->user_id,
                'name' => $name ?: 'Candidat',
                'description' => null,
                'media' => json_encode($media),
                'order' => $nextOrder,
                'status' => 'active',
                'score' => (int) $participant->score,
                'total_votes' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Candidature acceptée et candidat ajouté.',
        ]);
    }

    public function reject(
        Request $request,
        string $slug,
        int $contestId,
        int $applicationId
    ): JsonResponse {
        return $this->decideApplication(
            $request,
            $slug,
            $contestId,
            $applicationId,
            'rejected',
            'Candidature refusée.'
        );
    }

    public function cancel(
        Request $request,
        string $slug,
        int $contestId,
        int $applicationId
    ): JsonResponse {
        return $this->decideApplication(
            $request,
            $slug,
            $contestId,
            $applicationId,
            'cancelled',
            'Admission annulée.'
        );
    }

    private function decideApplication(
        Request $request,
        string $slug,
        int $contestId,
        int $applicationId,
        string $status,
        string $message
    ): JsonResponse {
        [, $contest] = $this->context($request, $slug, $contestId);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $participant = ContestParticipant::query()
            ->where('id', $applicationId)
            ->where('post_id', $contest->post_id)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'Candidature introuvable pour ce concours.',
            ], 404);
        }

        DB::transaction(function () use (
            $participant,
            $validated,
            $status,
            $request,
            $contest
        ) {
            $participant->update([
                'status' => $status,
                'decision_reason' => $validated['reason'],
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
            ]);

            if ($status === 'cancelled') {
                DB::table('contest_candidates')
                    ->where('post_id', $contest->post_id)
                    ->where('user_id', $participant->user_id)
                    ->whereNull('deleted_at')
                    ->update([
                        'status' => 'withdrawn',
                        'updated_at' => now(),
                    ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Ajout manuel d'un candidat.
     */
    public function store(Request $request, string $slug, int $contestId): JsonResponse
    {
        [, $contest] = $this->context($request, $slug, $contestId);

        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'media' => ['nullable', 'array', 'max:5'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:20480'],
        ]);

        if (!empty($data['user_id'])) {
            $exists = DB::table('contest_candidates')
                ->where('post_id', $contest->post_id)
                ->where('user_id', $data['user_id'])
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'user_id' => 'Cet utilisateur est déjà candidat.',
                ]);
            }
        }

        $media = [];

        foreach ($request->file('media', []) as $file) {
            $path = $file->store('contests/candidates', 'public');
            $mime = (string) $file->getMimeType();

            $media[] = [
                'type' => str_starts_with($mime, 'video/') ? 'video' : 'image',
                'path' => $path,
                'url' => $this->publicFileUrl($path),
                'mime_type' => $mime,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ];
        }

        $id = DB::table('contest_candidates')->insertGetId([
            'post_id' => $contest->post_id,
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'media' => json_encode($media),
            'order' => ((int) DB::table('contest_candidates')
                ->where('post_id', $contest->post_id)
                ->max('order')) + 1,
            'status' => 'active',
            'score' => 0,
            'total_votes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Candidat ajouté.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(
        Request $request,
        string $slug,
        int $contestId,
        int $candidateId
    ): JsonResponse {
        [, $contest] = $this->context($request, $slug, $contestId);

        $candidate = DB::table('contest_candidates')
            ->where('id', $candidateId)
            ->where('post_id', $contest->post_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Candidat introuvable.',
            ], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'in:active,disqualified,withdrawn'],
            'score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'order' => ['sometimes', 'integer', 'min:1'],
        ]);

        DB::table('contest_candidates')
            ->where('id', $candidate->id)
            ->update(array_merge($data, ['updated_at' => now()]));

        return response()->json([
            'success' => true,
            'message' => 'Candidat modifié.',
        ]);
    }

    public function destroy(
        Request $request,
        string $slug,
        int $contestId,
        int $candidateId
    ): JsonResponse {
        [, $contest] = $this->context($request, $slug, $contestId);

        $candidate = DB::table('contest_candidates')
            ->where('id', $candidateId)
            ->where('post_id', $contest->post_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Candidat introuvable.',
            ], 404);
        }

        $hasVotes = DB::table('candidate_votes')
            ->where('candidate_id', $candidate->id)
            ->exists();

        if ($hasVotes) {
            DB::table('contest_candidates')
                ->where('id', $candidate->id)
                ->update([
                    'status' => 'disqualified',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Ce candidat avait déjà des votes : il a été disqualifié.',
            ]);
        }

        DB::table('contest_candidates')
            ->where('id', $candidate->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Candidat supprimé.',
        ]);
    }
}
