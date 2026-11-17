<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Note la visite du jour d'une personne connectée (une écriture par jour au plus, plus
 * « vu à » toutes les 5 minutes). Sert aux statistiques de l'admin.
 */
class TrackVisit
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $user = $request->user();
        if (! $user || $request->is('admin*', 'livewire*', 'up')) {
            return $response;
        }
        try {
            $day = now()->toDateString();
            if (Cache::add('visit:'.$user->id.':'.$day, 1, now()->endOfDay())) {
                DB::table('user_visits')->insertOrIgnore(['user_id' => $user->id, 'day' => $day, 'hits' => 1, 'source' => $request->is('api/*') ? 'app' : 'web']);
            }
            if (Cache::add('seen:'.$user->id, 1, 300)) {
                DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $response;
    }
}
