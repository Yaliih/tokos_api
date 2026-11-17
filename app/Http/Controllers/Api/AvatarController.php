<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class AvatarController extends Controller
{
    
    /**
     * Liste tous les avatars disponibles.
     *
     * GET /api/avatars
     */
    public function index(): JsonResponse
    {
        $files = Storage::disk(config('filesystems.media', 'public'))->files('avatars');

        $avatars = collect($files)
            ->filter(fn ($f) => str_ends_with($f, '.png') || str_ends_with($f, '.jpg'))
            ->map(function ($file) {
                $name = basename($file, '.' . pathinfo($file, PATHINFO_EXTENSION));
                return [
                    'id'   => $name,
                    'seed' => $name,
                    'url'  => url("/storage/{$file}"),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data'    => [
                'avatars' => $avatars,
            ],
        ]);
    }
}