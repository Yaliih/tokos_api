<?php
// app/Http/Controllers/Api/ProfileController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Afficher le profil d'un utilisateur par son username
     */
    public function show(string $username): JsonResponse
    {
        $user = User::with('preferences')
            ->where('username', $username)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('User not found.'),
            ], 404);
        }

        // ⭐ Compter les followers et following
        $followersCount = $user->followers()->count();
        $followingCount = $user->following()->count();
        
        // ⭐ Vérifier si l'utilisateur connecté suit ce profil
        $isFollowing = false;
        if (auth()->check()) {
            $isFollowing = auth()->user()->isFollowing($user);
        }

        $stats = [
            'posts' => 0,
            'duels' => 0,
            'contests' => 0,
            'likes' => 0,
            'streak' => 0,
            'rank' => 0,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'username' => $user->username,
                'avatar' => $user->avatar,
                'cover' => $user->cover,
                'cover_type' => $user->cover_type ?? 'image',
                'bio' => $user->bio,
                'verified' => false,
                'level' => 0,
                'followers' => $followersCount,
                'following' => $followingCount,
                'is_following' => $isFollowing,
                'location' => $user->country,
                'city' => $user->city,
                'website' => $user->website,
                'joinedDate' => $user->created_at->toISOString(),
                'stats' => $stats,
                'badges' => [],
                'socialLinks' => [
                    'instagram' => null,
                    'twitter' => null,
                    'youtube' => null,
                    'github' => null,
                ],
            ]
        ]);
    }

    /**
     * Mettre à jour la couverture (image ou vidéo)
     */
    public function updateCover(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'cover' => 'required|file|max:20480',
            'cover_type' => 'required|in:image,video',
        ]);

        $file = $request->file('cover');
        $coverType = $request->cover_type;

        if ($coverType === 'image') {
            $request->validate([
                'cover' => 'image|mimes:jpeg,png,jpg,gif,webp|max:20480',
            ]);
        } else {
            $request->validate([
                'cover' => 'mimes:mp4,mov,avi,wmv,flv,webm|max:20480',
            ]);
        }

        // Supprimer l'ancienne couverture
        if ($user->getRawOriginal('cover') && Storage::disk(config('filesystems.media', 'public'))->exists($user->getRawOriginal('cover'))) {
            Storage::disk(config('filesystems.media', 'public'))->delete($user->getRawOriginal('cover'));
        }

        // Générer un nom unique pour le fichier
        $extension = $file->getClientOriginalExtension();
        $filename = time() . '_' . uniqid() . '.' . $extension;
        
        // Déterminer le dossier de stockage
        $folder = $coverType === 'image' ? 'covers/images' : 'covers/videos';
        $path = $file->storeAs($folder, $filename, config('filesystems.media', 'public'));

        $user->update([
            'cover' => $path,
            'cover_type' => $coverType,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Cover updated successfully.'),
            'data' => [
                'cover_url' => $user->cover_url,
                'cover_type' => $coverType,
            ]
        ]);
    }

    /**
     * Mettre à jour l'avatar
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        if ($user->getRawOriginal('avatar') && Storage::disk(config('filesystems.media', 'public'))->exists($user->getRawOriginal('avatar'))) {
            Storage::disk(config('filesystems.media', 'public'))->delete($user->getRawOriginal('avatar'));
        }

        $file = $request->file('avatar');
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('avatars', $filename, config('filesystems.media', 'public'));

        $user->update(['avatar' => $path]);

        return response()->json([
            'success' => true,
            'message' => __('Avatar updated successfully.'),
            'data' => [
                'avatar_url' => $user->avatar_url,
            ]
        ]);
    }

    /**
     * Mettre à jour le profil
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => 'sometimes|string|min:2|max:100',
            'last_name' => 'sometimes|string|min:2|max:100',
            'bio' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'website' => 'nullable|url|max:255',
            'username' => 'sometimes|string|min:3|max:30|regex:/^[a-zA-Z0-9_]+$/|unique:users,username,' . $user->id,
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => __('Profile updated successfully.'),
            'data' => [
                'user' => $user->fresh(),
            ]
        ]);
    }

    /**
     * Supprimer l'avatar
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->getRawOriginal('avatar') && Storage::disk(config('filesystems.media', 'public'))->exists($user->getRawOriginal('avatar'))) {
            Storage::disk(config('filesystems.media', 'public'))->delete($user->getRawOriginal('avatar'));
        }

        $user->update(['avatar' => null]);

        return response()->json([
            'success' => true,
            'message' => __('Avatar removed successfully.'),
        ]);
    }

    /**
     * Supprimer la couverture
     */
    public function deleteCover(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->getRawOriginal('cover') && Storage::disk(config('filesystems.media', 'public'))->exists($user->getRawOriginal('cover'))) {
            Storage::disk(config('filesystems.media', 'public'))->delete($user->getRawOriginal('cover'));
        }

        $user->update([
            'cover' => null,
            'cover_type' => 'image',
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Cover removed successfully.'),
        ]);
    }
}