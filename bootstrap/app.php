<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ✅ NE PAS appeler statefulApi() ici.
        // Le middleware "web" appliqué explicitement sur les routes SPA
        // suffit pour Next.js. Voir routes/api.php.

        // Site web : un visiteur non connecté est envoyé vers la page de connexion
        $middleware->redirectGuestsTo(fn (Request $request) => '/connexion?suite='.urlencode('/'.ltrim($request->path(), '/')));
        $middleware->redirectUsersTo('/');
        $middleware->alias(['feature' => \App\Http\Middleware\RequireFeature::class]);
        // Visite du jour (statistiques de l'admin)
        $middleware->appendToGroup('web', \App\Http\Middleware\TrackVisit::class);
        // Identifiant d'appareil (anti-triche) : cookie non chiffré, pour être lu aussi côté API
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureDeviceId::class);
        // Adresse d'Espace (lions.tokos.cd) : affiche la page de l'Espace
        $middleware->appendToGroup('web', \App\Http\Middleware\SpaceSite::class);
        $middleware->encryptCookies(except: [\App\Support\Device::COOKIE, 'tokos_lang']);
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackVisit::class);
        // Langue des messages : français par défaut, anglais possible (?lang=en, ou l'app avec X-Locale)
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocale::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            // L'API, et les envois en arrière-plan du site (fetch) : l'erreur revient en JSON, lisible par le script
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();