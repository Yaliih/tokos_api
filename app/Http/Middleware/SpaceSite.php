<?php

namespace App\Http\Middleware;

use App\Models\Space;
use Closure;
use Illuminate\Http\Request;

/**
 * Chaque Espace a son adresse : lions.sosa.social affiche la page de l'Espace « lions », comme un petit site.
 * Les autres pages ouvertes depuis cette adresse continuent sur le site principal.
 * Actif seulement si SPACE_DOMAIN est défini (et le DNS « *.domaine » pointé vers le serveur).
 */
class SpaceSite
{
    public function handle(Request $request, Closure $next)
    {
        $sub = self::subdomain($request->getHost());
        if ($sub === null) {
            return $next($request);
        }
        $main = self::mainUrl();
        $space = Space::where('slug', $sub)->where('is_active', true)->first();
        if (! $space || \App\Support\Features::off('space_sites')) {
            return redirect()->away($main.'/');
        }
        if ($request->path() !== '/' || ! $request->isMethod('GET')) {
            return redirect()->away($main.'/'.ltrim($request->getRequestUri(), '/'));
        }
        return \Illuminate\Routing\Router::toResponse($request, app(\App\Http\Controllers\Web\PageController::class)->spaceSite($request, $space->slug));
    }

    /** « lions » pour lions.sosa.social ; null sur le site principal (ou www, admin…). */
    public static function subdomain(string $host): ?string
    {
        $domain = strtolower((string) config('spaces.domain'));
        $host = strtolower($host);
        if (! $domain || ! str_ends_with($host, '.'.$domain)) {
            return null;
        }
        $sub = substr($host, 0, -strlen('.'.$domain));
        if ($sub === '' || str_contains($sub, '.') || in_array($sub, config('spaces.reserved_subdomains'), true)) {
            return null;
        }

        return $sub;
    }

    public static function mainUrl(): string
    {
        return rtrim(config('app.url'), '/');
    }

    /** Adresse publique d'un Espace, ou null si les adresses d'Espace ne sont pas activées. */
    public static function urlFor(Space $space): ?string
    {
        $domain = config('spaces.domain');
        if (! $domain || strlen($space->slug) > 63 || in_array($space->slug, config('spaces.reserved_subdomains'), true)
            || ! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $space->slug)) {
            return null;
        }

        return $space->slug.'.'.$domain;
    }
}
