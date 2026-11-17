<?php
// app/Http/Controllers/Api/FollowController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    /**
     * Suivre un utilisateur
     */
    public function follow(Request $request, string $username): JsonResponse
    {
        $userToFollow = User::where('username', $username)->first();
        
        if (!$userToFollow) {
            return response()->json([
                'success' => false,
                'message' => __('User not found.'),
            ], 404);
        }

        $currentUser = $request->user();

        if ($currentUser->id === $userToFollow->id) {
            return response()->json([
                'success' => false,
                'message' => __('You cannot follow yourself.'),
            ], 422);
        }

        if ($currentUser->isFollowing($userToFollow)) {
            return response()->json([
                'success' => false,
                'message' => __('You are already following this user.'),
            ], 422);
        }

        $currentUser->follow($userToFollow);
        app(\App\Services\Social\SocialHooks::class)->followed($currentUser, $userToFollow);

        return response()->json([
            'success' => true,
            'message' => __('User followed successfully.'),
            'data' => [
                'is_following' => true,
                'followers_count' => $userToFollow->followers()->count(),
            ]
        ]);
    }

    /**
     * Ne plus suivre un utilisateur
     */
    public function unfollow(Request $request, string $username): JsonResponse
    {
        $userToUnfollow = User::where('username', $username)->first();
        
        if (!$userToUnfollow) {
            return response()->json([
                'success' => false,
                'message' => __('User not found.'),
            ], 404);
        }

        $currentUser = $request->user();

        if (!$currentUser->isFollowing($userToUnfollow)) {
            return response()->json([
                'success' => false,
                'message' => __('You are not following this user.'),
            ], 422);
        }

        $currentUser->unfollow($userToUnfollow);

        return response()->json([
            'success' => true,
            'message' => __('User unfollowed successfully.'),
            'data' => [
                'is_following' => false,
                'followers_count' => $userToUnfollow->followers()->count(),
            ]
        ]);
    }

    /**
     * Liste des followers
     */
    public function followers(string $username): JsonResponse
    {
        $user = User::where('username', $username)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('User not found.'),
            ], 404);
        }

        $followers = $user->followers()
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.username', 'users.avatar')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'username' => $user->username,
                    'avatar' => $user->avatar,
                    'is_following' => auth()->user() ? auth()->user()->isFollowing($user) : false,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->full_name,
                    'username' => $user->username,
                    'avatar' => $user->avatar,
                ],
                'count' => $followers->count(),
                'followers' => $followers,
            ]
        ]);
    }

    /**
     * Liste des following
     */
    public function following(string $username): JsonResponse
    {
        $user = User::where('username', $username)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('User not found.'),
            ], 404);
        }

        $following = $user->following()
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.username', 'users.avatar')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'username' => $user->username,
                    'avatar' => $user->avatar,
                    'is_following' => auth()->user() ? auth()->user()->isFollowing($user) : false,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->full_name,
                    'username' => $user->username,
                    'avatar' => $user->avatar,
                ],
                'count' => $following->count(),
                'following' => $following,
            ]
        ]);
    }
}