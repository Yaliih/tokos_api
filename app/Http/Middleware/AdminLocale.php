<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** L'admin parle français (traductions fournies par Filament). */
class AdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale('fr');

        return $next($request);
    }
}
