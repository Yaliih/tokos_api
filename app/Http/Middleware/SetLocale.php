<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La langue des messages (erreurs de validation…) : français par défaut, anglais possible.
 * Ordre : ?lang= (retenu dans un cookie), le cookie « tokos_lang », l'en-tête X-Locale envoyé par l'app,
 * sinon le français. On ne suit pas la langue du navigateur : Sosa est en français.
 */
class SetLocale
{
    public const SUPPORTED = ['fr', 'en'];

    public const DEFAULT = 'fr';

    public function handle(Request $request, Closure $next): Response
    {
        $asked = strtolower(substr((string) $request->query('lang', ''), 0, 2));
        $locale = in_array($asked, self::SUPPORTED, true) ? $asked : null;
        $locale ??= in_array($c = strtolower((string) $request->cookie('tokos_lang')), self::SUPPORTED, true) ? $c : null;
        $locale ??= in_array($h = strtolower(substr((string) $request->header('X-Locale', ''), 0, 2)), self::SUPPORTED, true) ? $h : null;
        $locale ??= self::DEFAULT;

        app()->setLocale($locale);
        \Carbon\Carbon::setLocale($locale);

        $response = $next($request);
        // Choix fait par lien (?lang=en) : on le garde un an
        if ($asked && in_array($asked, self::SUPPORTED, true) && method_exists($response, 'withCookie')) {
            $response->withCookie(cookie()->forever('tokos_lang', $asked));
        }

        return $response;
    }
}
