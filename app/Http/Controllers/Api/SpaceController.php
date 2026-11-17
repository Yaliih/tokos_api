<?php
// app/Http/Controllers/Api/SpaceController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\SpaceActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SpaceController extends Controller
{
    /**
     * Espaces liés à l'utilisateur : propriétaire, gestionnaire ou membre.
     *
     * GET /api/spaces/mine?search=&page=1&per_page=12
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 30);
        $search = trim((string) $request->query('search', ''));

        $query = Space::query()
            ->select([
                'id',
                'owner_id',
                'name',
                'slug',
                'avatar',
                'bio',
                'category',
                'location',
                'is_private',
                'is_verified',
                'is_active',
                'trust_score',
                'created_at',
            ])
            ->where(function ($spaceQuery) use ($user) {
                $spaceQuery
                    ->where('owner_id', $user->id)
                    ->orWhereHas('managers', function ($managerQuery) use ($user) {
                        $managerQuery->where('users.id', $user->id);
                    })
                    ->orWhereHas('members', function ($memberQuery) use ($user) {
                        $memberQuery->where('users.id', $user->id);
                    });
            })
            ->withCount('members')
            ->with([
                'managers' => function ($managerQuery) use ($user) {
                    $managerQuery
                        ->where('users.id', $user->id)
                        ->select('users.id');
                },
            ]);

        $this->applyListSearch($query, $search);

        $spaces = $query
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $spaces->getCollection()->transform(function (Space $space) use ($user) {
            if ((int) $space->owner_id === (int) $user->id) {
                $role = 'admin';
            } else {
                $manager = $space->managers->first();
                $role = $manager?->pivot?->role;

                if (!in_array($role, ['admin', 'editor', 'viewer'], true)) {
                    $role = 'viewer';
                }
            }

            $space->setAttribute('user_role', $role);
            $space->unsetRelation('managers');

            return $space;
        });

        return response()->json([
            'success' => true,
            'data' => $spaces,
        ]);
    }

    /**
     * Espaces publics auxquels l'utilisateur n'est pas encore lié.
     *
     * GET /api/spaces/discover?search=&page=1&per_page=12
     */
    public function discover(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 30);
        $search = trim((string) $request->query('search', ''));

        $query = Space::query()
            ->select([
                'id',
                'name',
                'slug',
                'avatar',
                'bio',
                'category',
                'location',
                'is_private',
                'is_verified',
                'trust_score',
            ])
            ->where('is_active', true)
            ->where('is_private', false)
            ->where('owner_id', '!=', $user->id)
            ->whereDoesntHave('managers', function ($managerQuery) use ($user) {
                $managerQuery->where('users.id', $user->id);
            })
            ->whereDoesntHave('members', function ($memberQuery) use ($user) {
                $memberQuery->where('users.id', $user->id);
            })
            ->withCount('members');

        $this->applyListSearch($query, $search);

        $spaces = $query
            ->orderByDesc('trust_score')
            ->orderByDesc('members_count')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $spaces,
        ]);
    }

    /**
     * Ancien endpoint conservé pour compatibilité.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->mine($request);
    }

    private function applyListSearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
        $like = "%{$escaped}%";

        $query->where(function ($searchQuery) use ($like) {
            $searchQuery
                ->where('name', 'like', $like)
                ->orWhere('bio', 'like', $like)
                ->orWhere('category', 'like', $like)
                ->orWhere('location', 'like', $like);
        });
    }

    /**
     * Créer un espace
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|min:3|max:100|unique:spaces,name',
            'bio' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'location' => 'nullable|string|max:100',
            'is_private' => 'boolean',
        ]);

        $space = Space::create([
            'owner_id' => $user->id,
            'name' => $validated['name'],
            'bio' => $validated['bio'] ?? null,
            'category' => $validated['category'] ?? null,
            'website' => $validated['website'] ?? null,
            'location' => $validated['location'] ?? null,
            'is_private' => $validated['is_private'] ?? false,
            'trust_score' => 10,
        ]);

        $space->addManager($user, 'admin');
        app(\App\Services\Social\SocialHooks::class)->createdSpace($user, $space);

        // Log activity
        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'space_created',
            'description' => "{$user->full_name} created the space {$space->name}",
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Space created successfully.'),
            'data' => $space,
        ], 201);
    }

    /**
     * Afficher un espace
     */
    public function show(Request $request, string $slug): JsonResponse
{
    $space = Space::where('slug', $slug)->first();

    if (!$space) {
        return response()->json([
            'success' => false,
            'message' => __('Space not found.'),
        ], 404);
    }

    $user = auth('sanctum')->user() ?? $request->user();
    $role = $this->resolveUserRole($space, $user);
    $isStaff = in_array($role, ['admin', 'editor'], true);
    $isMember = $user ? $space->isMember($user) : false;

    if ($isStaff) {
        $space->load(['owner', 'managers']);
        $spaceData = $space->toArray();
        $managers = $space->managers->map(function ($manager) {
            return [
                'id' => $manager->id,
                'name' => $manager->full_name,
                'username' => $manager->username,
                'avatar' => $manager->avatar_url ?? $manager->avatar,
                'role' => $manager->pivot->role,
            ];
        })->values();
        $recentActivities = $space->activities()->limit(10)->get();
    } else {
        $spaceData = [
            'id' => $space->id,
            'name' => $space->name,
            'slug' => $space->slug,
            'avatar_url' => $space->avatar_url,
            'cover_url' => $space->cover_url,
            'bio' => $space->bio,
            'category' => $space->category,
            'location' => $space->location,
            'is_private' => (bool) $space->is_private,
            'is_verified' => (bool) $space->is_verified,
            'members_count' => $space->members_count,
            'trust_score' => (int) $space->trust_score,
            'trust_level' => $space->trust_level,
            'total_posts' => $space->total_posts,
            'created_at' => $space->created_at,
        ];
        $managers = collect();
        $recentActivities = collect();
    }

    // ⭐ FUSION : ajoute les métadonnées DIRECTEMENT dans space
    $spaceData = array_merge($spaceData, [
        'user_role'  => $role,
        'is_member'  => $isMember,
        'is_manager' => in_array($role, ['admin', 'editor', 'viewer'], true),
        'is_admin'   => $role === 'admin',
        'is_editor'  => $role === 'editor',
        'can_manage' => $isStaff,
        'can_post'   => $isStaff,
        'owner_id'   => $space->owner_id,
    ]);

    $data = ['space' => $spaceData];

    if ($isStaff) {
        $data['managers'] = $managers;
        $data['recent_activities'] = $recentActivities;
    }

    return response()->json([
        'success' => true,
        'data' => $data,
    ]);
}

    private function resolveUserRole(Space $space, $user): string
    {
        if (!$user) {
            return 'visitor';
        }

        if ((int) $space->owner_id === (int) $user->id) {
            return 'admin';
        }

        $managerRole = $space->managers()
            ->where('users.id', $user->id)
            ->value('space_managers.role');

        if (in_array($managerRole, ['admin', 'editor', 'viewer'], true)) {
            return $managerRole;
        }

        return $space->isMember($user) ? 'viewer' : 'visitor';
    }

    /**
     * Mettre à jour un espace
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if (!$space->canEditInfo($user)) {
            return response()->json(['success' => false, 'message' => __('You do not have permission to manage this space.')], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|min:3|max:100|unique:spaces,name,' . $space->id,
            'bio' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'location' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'social_links' => 'nullable|array',
            'is_private' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $space->update($validated);

        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'space_updated',
            'description' => "{$user->full_name} updated the space information",
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Space updated successfully.'),
            'data' => $space,
        ]);
    }

    /**
     * Supprimer un espace
     */
    public function destroy(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if ($space->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => __('Only the owner can delete this space.')], 403);
        }

        $space->delete();

        return response()->json([
            'success' => true,
            'message' => __('Space deleted successfully.'),
        ]);
    }

    /**
     * Mettre à jour l'avatar
     */
    public function updateAvatar(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if (!$space->canEditInfo($user)) {
            return response()->json(['success' => false, 'message' => __('Unauthorized.')], 403);
        }
        // Vérifier si un fichier est présent
    
        $request->validate([
            'avatar' => 'required|image|max:2048',
        ]);

        if ($space->avatar && Storage::disk(config('filesystems.media', 'public'))->exists($space->avatar)) {
            Storage::disk(config('filesystems.media', 'public'))->delete($space->avatar);
        }

        $path = $request->file('avatar')->store('spaces/avatars', config('filesystems.media', 'public'));
        $space->update(['avatar' => $path]);

        return response()->json([
            'success' => true,
            'message' => __('Avatar updated.'),
            'data' => ['avatar_url' => $space->avatar_url],
        ]);
    }

    /**
     * Supprimer l'avatar
     */
    public function deleteAvatar(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if (!$space->canEditInfo($user)) {
            return response()->json(['success' => false, 'message' => __('Unauthorized.')], 403);
        }

        if ($space->avatar && Storage::disk(config('filesystems.media', 'public'))->exists($space->avatar)) {
            Storage::disk(config('filesystems.media', 'public'))->delete($space->avatar);
        }
        $space->update(['avatar' => null]);

        return response()->json([
            'success' => true,
            'message' => __('Avatar removed.'),
        ]);
    }

    /**
     * Mettre à jour la couverture
     */
    public function updateCover(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if (!$space->canEditInfo($user)) {
            return response()->json(['success' => false, 'message' => __('Unauthorized.')], 403);
        }

        $request->validate([
            'cover' => 'required|file|max:20480',
            'cover_type' => 'required|in:image,video',
        ]);

        if ($space->cover && Storage::disk(config('filesystems.media', 'public'))->exists($space->cover)) {
            Storage::disk(config('filesystems.media', 'public'))->delete($space->cover);
        }

        $file = $request->file('cover');
        $folder = $request->cover_type === 'image' ? 'spaces/covers/images' : 'spaces/covers/videos';
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($folder, $filename, config('filesystems.media', 'public'));

        $space->update([
            'cover' => $path,
            'cover_type' => $request->cover_type,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Cover updated.'),
            'data' => ['cover_url' => $space->cover_url],
        ]);
    }

    /**
     * Supprimer la couverture
     */
    public function deleteCover(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if (!$space->canEditInfo($user)) {
            return response()->json(['success' => false, 'message' => __('Unauthorized.')], 403);
        }

        if ($space->cover && Storage::disk(config('filesystems.media', 'public'))->exists($space->cover)) {
            Storage::disk(config('filesystems.media', 'public'))->delete($space->cover);
        }
        $space->update(['cover' => null, 'cover_type' => 'image']);

        return response()->json([
            'success' => true,
            'message' => __('Cover removed.'),
        ]);
    }

    /**
     * Rejoindre un espace
     */
    public function join(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if ($space->isMember($user)) {
            return response()->json(['success' => false, 'message' => __('Already a member.')], 422);
        }

        $space->addMember($user);
        app(\App\Services\Social\SocialHooks::class)->joinedSpace($user, $space);

        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'member_joined',
            'description' => "{$user->full_name} joined the space",
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Joined the space.'),
            'data' => [
                'is_member' => true,
                'members_count' => $space->members_count,
            ]
        ]);
    }

    /**
     * Quitter un espace
     */
    public function leave(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if ((int) $space->owner_id === (int) $user->id) {
            return response()->json(['success' => false, 'message' => __('Owner cannot leave.')], 422);
        }

        $role = $this->resolveUserRole($space, $user);
        if (in_array($role, ['admin', 'editor'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Un administrateur ou éditeur doit être retiré depuis la gestion de l’espace.',
            ], 422);
        }

        $wasMember = $space->isMember($user);
        $wasViewerManager = $role === 'viewer' && $space->isManager($user);

        if (!$wasMember && !$wasViewerManager) {
            return response()->json(['success' => false, 'message' => __('Not a member.')], 422);
        }

        if ($wasMember) {
            $space->removeMember($user);
        }
        if ($wasViewerManager) {
            $space->managers()->detach($user->id);
        }

        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'member_left',
            'description' => "{$user->full_name} left the space",
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Left the space.'),
            'data' => [
                'is_member' => false,
                'user_role' => 'visitor',
                'members_count' => $space->fresh()->members_count,
            ],
        ]);
    }

    /**
     * Liste des membres
     */
    public function members(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = auth('sanctum')->user() ?? $request->user();
        $role = $this->resolveUserRole($space, $user);
        $isStaff = in_array($role, ['admin', 'editor'], true);

        $managerIds = $space->managers()->pluck('users.id');
        $memberQuery = $space->members()
        ->select([
            'users.id',
            'users.first_name',
            'users.last_name',
            'users.username',
            'users.gender',
            'users.avatar',
        ]);

        if (!$isStaff) {
            // Les visiteurs/viewers ne voient ni le propriétaire,
            // ni les administrateurs, ni les éditeurs/viewers internes.
            $memberQuery
                ->where('users.id', '!=', $space->owner_id)
                ->whereNotIn('users.id', $managerIds);
        }
    
        $members = $memberQuery->get()->map(function ($member) {
            
            return [
                'id' => $member->id,
                'name' => $member->full_name,
                'username' => $member->username,
                'avatar' => $member->avatar_url ,
                'role' => 'member',
                'joined_at' => $member->pivot->accepted_at,
            ];
        });

        if ($isStaff) {
            $staff = $space->managers()
                ->select('users.id', 'users.first_name', 'users.last_name', 'users.username','users.gender','users.avatar')
                ->get()
                ->map(function ($manager) use ($space) {
                    return [
                        'id' => $manager->id,
                        'name' => $manager->full_name,
                        'username' => $manager->username,
                        'avatar' => $manager->avatar_url ?? $manager->avatar,
                        'role' => (int) $manager->id === (int) $space->owner_id
                            ? 'admin'
                            : $manager->pivot->role,
                        'joined_at' => $manager->pivot->joined_at,
                    ];
                });

            $members = $staff
                ->concat($members)
                ->unique('id')
                ->values();
                
        }

        return response()->json([
            'success' => true,
            'data' => $members,
            'meta' => [
                'viewer_role' => $role,
                'staff_visible' => $isStaff,
            ],
        ]);
    }

    /**
     * Activités récentes
     */
    public function activities(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = auth('sanctum')->user() ?? $request->user();
        $role = $this->resolveUserRole($space, $user);

        if (!in_array($role, ['admin', 'editor'], true)) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthorized.'),
            ], 403);
        }

        $activities = $space->activities()
            ->with('user')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $activities,
        ]);
    }

}