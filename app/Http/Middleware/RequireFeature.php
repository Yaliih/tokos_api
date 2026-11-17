<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;

/** Route d'une fonction cachée depuis l'admin : 404, comme si elle n'existait pas. */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string ...$features)
    {
        foreach ($features as $f) {
            abort_if(Features::off($f), 404);
        }

        return $next($request);
    }
}
