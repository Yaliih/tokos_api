<?php
// app/Http/Controllers/Api/SpaceManagerController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\SpaceActivity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpaceManagerController extends Controller
{
    /**
     * Liste des gestionnaires
     */
    public function index(string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => 'Space not found.'], 404);
        }

        $managers = $space->managers->map(function($m) {
            return [
                'id' => $m->id,
                'name' => $m->full_name,
                'username' => $m->username,
                'avatar' => $m->avatar_url,
                'role' => $m->pivot->role,
                'joined_at' => $m->pivot->joined_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $managers,
        ]);
    }

    /**
     * Ajouter un gestionnaire
     */
    public function addManager(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => 'Space not found.'], 404);
        }

        $user = $request->user();
        if (!$space->isAdmin($user) && $space->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Only admins can add managers.'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'nullable|in:admin,editor,viewer',
        ]);

        $newManager = User::find($validated['user_id']);
        
        if ($space->isManager($newManager)) {
            return response()->json(['success' => false, 'message' => 'Already a manager.'], 422);
        }

        if ($newManager->id === $space->owner_id) {
            return response()->json(['success' => false, 'message' => 'Owner is already an admin.'], 422);
        }

        $space->addManager($newManager, $validated['role'] ?? 'editor');

        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'manager_added',
            'description' => "{$user->full_name} added {$newManager->full_name} as a manager",
            'metadata' => ['role' => $validated['role'] ?? 'editor'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Manager added.',
            'data' => $space->managers->map(function($m) {
                return [
                    'id' => $m->id,
                    'name' => $m->full_name,
                    'username' => $m->username,
                    'avatar' => $m->avatar,
                    'role' => $m->pivot->role,
                ];
            }),
        ]);
    }

    /**
     * Mettre à jour le rôle d'un gestionnaire
     */
    public function updateManager(Request $request, string $slug, int $managerId): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => 'Space not found.'], 404);
        }

        $user = $request->user();
        if (!$space->isAdmin($user) && $space->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Only admins can update roles.'], 403);
        }

        $validated = $request->validate([
            'role' => 'required|in:admin,editor,viewer',
        ]);

        $manager = User::find($managerId);
        if (!$manager) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        if ($manager->id === $space->owner_id) {
            return response()->json(['success' => false, 'message' => 'Cannot change owner role.'], 422);
        }

        if (!$space->isManager($manager)) {
            return response()->json(['success' => false, 'message' => 'User is not a manager.'], 422);
        }

        $space->updateManagerRole($manager, $validated['role']);

        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'manager_role_updated',
            'description' => "{$user->full_name} updated {$manager->full_name}'s role to {$validated['role']}",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Role updated.',
        ]);
    }

    /**
     * Retirer un gestionnaire
     */
    public function removeManager(Request $request, string $slug, int $managerId): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => 'Space not found.'], 404);
        }

        $user = $request->user();
        if (!$space->isAdmin($user) && $space->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Only admins can remove managers.'], 403);
        }

        $manager = User::find($managerId);
        if (!$manager) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        if ($manager->id === $space->owner_id) {
            return response()->json(['success' => false, 'message' => 'Cannot remove the owner.'], 422);
        }

        if (!$space->isManager($manager)) {
            return response()->json(['success' => false, 'message' => 'User is not a manager.'], 422);
        }

        $space->removeManager($manager);

        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'manager_removed',
            'description' => "{$user->full_name} removed {$manager->full_name} from managers",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Manager removed.',
        ]);
    }
}