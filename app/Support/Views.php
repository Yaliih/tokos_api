<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compte les vues : une personne = une vue, même si elle revient plusieurs fois.
 * - Connecté : on retient son compte.
 * - Visiteur du site : un identifiant gardé dans un cookie (2 ans).
 * - Visiteur de l'API sans compte : son adresse IP et son appareil.
 */
class Views
{
    public const COOKIE = 'tokos_vid';

    public static function record(Request $request, Model $model): void
    {
        $user = $request->user() ?? $request->user('sanctum');
        if ($user) {
            $key = 'u:'.$user->id;
        } elseif ($request->hasSession()) {
            $vid = $request->cookie(self::COOKIE);
            if (! is_string($vid) || ! preg_match('/^[a-f0-9-]{36}$/', $vid)) {
                $vid = (string) Str::uuid();
                Cookie::queue(self::COOKIE, $vid, 60 * 24 * 730);
            }
            $key = 'g:'.$vid;
        } else {
            $key = 'g:'.substr(hash('sha256', $request->ip().'|'.$request->userAgent()), 0, 40);
        }

        try {
            $inserted = DB::table('content_views')->insertOrIgnore([
                'viewable_type' => $model::class,
                'viewable_id' => $model->getKey(),
                'viewer_key' => $key,
                'user_id' => $user?->id,
                'created_at' => now(),
            ]);
            if ($inserted > 0) {
                $model->increment('views_count');
            }
        } catch (QueryException $e) {
            // Table ou colonne absente tant que « php artisan migrate » n'a pas été lancé
            report($e);
        }
    }
}
