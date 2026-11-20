<?php

/*
|--------------------------------------------------------------------------
| Site web Sosa (Blade, servi par Laravel)
|--------------------------------------------------------------------------
| Même base de données, mêmes services et mêmes règles que l'app mobile.
| Les pages publiques (compétitions, duels, profils, Espaces) s'ouvrent sans
| compte : c'est ce qui rend les liens partagés utiles.
*/

use App\Http\Controllers\Web\ActionController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\PageController;
use Illuminate\Support\Facades\Route;

// Fichiers du site servis avec leur version dans l'adresse : /a/tokos.1794959040.css
// Une nouvelle version = une nouvelle adresse, donc un cache (navigateur ou CDN) ne peut plus
// servir un vieux CSS après un déploiement.
Route::get('/a/{file}', function (string $file) {
    if (! preg_match('/^([a-z0-9\-]+)\.(\d+)\.(css|js)$/', $file, $m)) {
        abort(404);
    }
    $ext = $m[3];
    $path = public_path(($ext === 'css' ? 'css' : 'js').'/'.$m[1].'.'.$ext);
    abort_unless(is_file($path), 404);

    return response()->file($path, [
        'Content-Type' => $ext === 'css' ? 'text/css; charset=utf-8' : 'application/javascript; charset=utf-8',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('file', '[A-Za-z0-9.\-]+');
// Plan du site : aide Google à trouver les profils, les Espaces et les compétitions publiques
// (les comptes désactivés et les profils privés n'y sont pas).
// Compétitions d'un profil : page dédiée avec onglets et tri
// Compétitions d'un Espace : même page que pour un profil
Route::get('/espaces/{slug}/competitions', [\App\Http\Controllers\Web\PageController::class, 'spaceCompetitions'])->name('space.competitions');
Route::get('/@{handle}/competitions', [\App\Http\Controllers\Web\PageController::class, 'profileCompetitions'])
    ->where('handle', '[A-Za-z0-9_.]+')->name('profile.competitions');
Route::get('/sitemap.xml', function () {
    $urls = [];
    \App\Models\User::query()->whereNotNull('username')->where('is_active', true)->where('is_private', false)
        ->orderByDesc('last_seen_at')->limit(5000)->pluck('username')
        ->each(function ($u) use (&$urls) { $urls[] = url('/@'.$u); });
    \App\Models\Space::query()->where('is_active', true)->orderByDesc('id')->limit(2000)->pluck('slug')
        ->each(function ($s) use (&$urls) { $urls[] = url('/espaces/'.$s); });
    \App\Models\Competition::query()->whereNull('hidden_at')->orderByDesc('id')->limit(2000)->pluck('slug')
        ->each(function ($c) use (&$urls) { $urls[] = url('/c/'.$c); });
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    foreach ($urls as $u) {
        $xml .= '  <url><loc>'.htmlspecialchars($u, ENT_XML1).'</loc></url>'."\n";
    }
    $xml .= '</urlset>'."\n";

    return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
})->name('sitemap');
// Pages publiques
Route::get('/', [PageController::class, 'home']);
Route::get('/decouvrir', [PageController::class, 'discover']);
Route::get('/voter', [PageController::class, 'swipe'])->middleware('feature:swipe');
Route::get('/competitions', fn (\Illuminate\Http\Request $r) => redirect('/decouvrir'.($r->getQueryString() ? '?'.$r->getQueryString() : ''), 301));
Route::get('/c/{competition}', [PageController::class, 'competition']);
Route::get('/m/{match}', [PageController::class, 'match'])->whereNumber('match');
Route::get('/m/{match}/fragment', [PageController::class, 'matchFragment'])->whereNumber('match');
Route::get('/m/{match}/apercu.png', [PageController::class, 'matchImage'])->whereNumber('match');
Route::get('/c/{competition}/apercu.png', [PageController::class, 'competitionImage']);
Route::view('/conditions', 'web.pages.legal', ['page' => 'conditions', 'title' => 'Conditions d\'utilisation', 'updated' => '1er novembre 2026', 'hideRail' => true]);
Route::view('/confidentialite', 'web.pages.legal', ['page' => 'confidentialite', 'title' => 'Confidentialité', 'updated' => '1er novembre 2026', 'hideRail' => true]);
Route::get('/espaces/{slug}/site', [PageController::class, 'spaceSite'])->middleware('feature:space_sites');
Route::get('/c/{competition}/commentaires', [PageController::class, 'commentsFragment']);
Route::get('/classements', [PageController::class, 'leaderboards'])->middleware('feature:leaderboard');
Route::get('/a-suivre', [PageController::class, 'suggestions']); // « Tout voir » des personnes à suivre
Route::get('/espaces', [PageController::class, 'spaces'])->middleware('feature:spaces');
Route::get('/espaces/{slug}', [PageController::class, 'space'])->middleware('feature:spaces');
Route::get('/@{username}', [PageController::class, 'profile']);
Route::get('/@{username}/abonnes', [PageController::class, 'follows'])->defaults('list', 'abonnes');
Route::get('/@{username}/abonnements', [PageController::class, 'follows'])->defaults('list', 'abonnements');
Route::get('/p/{slug}', [PageController::class, 'post']);
Route::get('/i/{token}', [ActionController::class, 'phoneInvite'])->middleware('feature:phone_invites')->where('token', '[A-Za-z0-9]{20,40}');
Route::get('/c/{competition}/duels-apercu', [PageController::class, 'duelsSheet']); // fenêtre « Duels / Relever le défi » du fil
Route::get('/fil', [PageController::class, 'feedFragment']); // défilement infini du fil

// Application Android (TWA) : lien de confiance entre l'app du Play Store et ce site
Route::get('/.well-known/assetlinks.json', function () {
    $package = config('services.twa.package');
    $fingerprints = array_values(array_filter(array_map('trim', explode(',', (string) config('services.twa.sha256')))));
    abort_unless($package && $fingerprints, 404);

    return response()->json([[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => ['namespace' => 'android_app', 'package_name' => $package, 'sha256_cert_fingerprints' => $fingerprints],
    ]]);
});

// Connexion / inscription
Route::middleware('guest')->group(function () {
    Route::get('/connexion', [AuthController::class, 'showLogin'])->name('web.login');
    Route::post('/connexion', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/mot-de-passe-oublie', [AuthController::class, 'showForgot']);
    Route::post('/mot-de-passe-oublie', [AuthController::class, 'sendResetCode'])->middleware('throttle:5,1');
    Route::post('/mot-de-passe-oublie/nouveau', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('/mot-de-passe-oublie/recommencer', [AuthController::class, 'restartForgot']);
    Route::get('/inscription', [AuthController::class, 'showRegister'])->middleware('feature:registration');
    Route::post('/inscription', [AuthController::class, 'register'])->middleware(['throttle:10,1', 'feature:registration']);
});
Route::post('/deconnexion', [AuthController::class, 'logout'])->middleware('auth');

// Notifications push du navigateur : aussi sans compte (annonces à tout le monde)
Route::get('/push/cle', [\App\Http\Controllers\Web\PushController::class, 'key'])->middleware('feature:push');
Route::post('/push/abonner', [\App\Http\Controllers\Web\PushController::class, 'subscribe'])->middleware(['throttle:10,1', 'feature:push']);
Route::post('/push/desabonner', [\App\Http\Controllers\Web\PushController::class, 'unsubscribe'])->middleware('throttle:10,1');

// Actions (connecté)
Route::middleware('auth')->group(function () {
    Route::get('/creer', [PageController::class, 'create']);
    Route::post('/creer', [ActionController::class, 'store']);
    Route::get('/notifications', [PageController::class, 'notifications']);
    Route::get('/defis-recus', [PageController::class, 'invitations']);
    Route::post('/invitations/{invitation}', [ActionController::class, 'respondInvitation'])->whereNumber('invitation');

    Route::post('/c/{competition}/participer', [ActionController::class, 'enter'])->middleware('throttle:20,1');
    Route::post('/c/{competition}/retirer/{entry}', [ActionController::class, 'withdraw']);
    Route::post('/c/{competition}/candidatures/{entry}', [ActionController::class, 'manageEntry']);
    Route::post('/c/{competition}/lancer', [ActionController::class, 'start']);
    Route::post('/c/{competition}/annuler', [ActionController::class, 'cancel']);
    Route::post('/c/{competition}/remise', [ActionController::class, 'prizeProof'])->middleware('throttle:10,1');
    Route::post('/c/{competition}/modifier', [ActionController::class, 'edit'])->middleware(['throttle:20,1', 'feature:editing']);
    Route::post('/c/{competition}/inviter', [ActionController::class, 'invite']);
    Route::post('/c/{competition}/candidats', [ActionController::class, 'addCandidate']);
    Route::post('/c/{competition}/proposer', [ActionController::class, 'propose'])->middleware('feature:space_competitions');
    Route::post('/c/{competition}/propositions/{proposal}', [ActionController::class, 'selectProposal']);
    Route::post('/c/{competition}/voter/{entry}', [ActionController::class, 'voteEntry'])->middleware('throttle:60,1');
    Route::post('/c/{competition}/jury', [ActionController::class, 'setJury'])->middleware('feature:jury');
    Route::post('/c/{competition}/jury/{entry}', [ActionController::class, 'juryScore'])->middleware('feature:jury');
    Route::post('/c/{competition}/aimer', [ActionController::class, 'like']);
    Route::post('/c/{competition}/commenter', [ActionController::class, 'comment'])->middleware(['throttle:20,1', 'feature:comments']);
    Route::post('/commentaires/{comment}/aimer', [ActionController::class, 'likeComment'])->whereNumber('comment');
    Route::get('/recherche/participants', [ActionController::class, 'suggest'])->middleware('throttle:60,1');

    Route::post('/m/{match}/vote', [ActionController::class, 'vote'])->whereNumber('match')->middleware('throttle:60,1');
    Route::post('/m/{match}/reagir', [ActionController::class, 'react'])->whereNumber('match');
    Route::post('/m/{match}/prestation', [ActionController::class, 'roundSubmit'])->whereNumber('match')->middleware('throttle:10,1');
    Route::post('/m/{match}/revanche', [ActionController::class, 'rematch'])->whereNumber('match')->middleware('feature:rematch');

    Route::post('/suivre/{username}', [ActionController::class, 'follow']);
    Route::post('/localiser', [ActionController::class, 'locate'])->middleware('throttle:12,1');
    Route::post('/interets', [ActionController::class, 'saveInterests']);
    Route::post('/ville', [ActionController::class, 'saveCity']);
    Route::get('/mes-espaces', [PageController::class, 'mySpaces']);
    Route::get('/lieux', [ActionController::class, 'places'])->middleware('throttle:60,1');
    Route::post('/signaler', [ActionController::class, 'report'])->middleware('throttle:20,1');
    Route::get('/portefeuille', [\App\Http\Controllers\Web\CoinController::class, 'wallet'])->middleware('feature:coins');
    Route::post('/portefeuille/acheter', [\App\Http\Controllers\Web\CoinController::class, 'buy'])->middleware(['throttle:10,1', 'feature:buy_coins_web']);
    Route::post('/portefeuille/retirer', [\App\Http\Controllers\Web\CoinController::class, 'withdraw'])->middleware(['throttle:5,1', 'feature:withdrawals']);
    Route::post('/cadeau', [\App\Http\Controllers\Web\CoinController::class, 'gift'])->middleware(['throttle:30,1', 'feature:gifts']);
    Route::get('/parametres/certification', [\App\Http\Controllers\Web\CertificationController::class, 'show'])->middleware('feature:certification');
    Route::post('/parametres/certification', [\App\Http\Controllers\Web\CertificationController::class, 'submit'])->middleware(['throttle:5,1', 'feature:certification']);
    Route::get('/parametres/profil', [PageController::class, 'editProfile']);
    Route::get('/parametres/mot-de-passe', [PageController::class, 'passwordPage']);
    Route::post('/parametres/mot-de-passe/code', [ActionController::class, 'sendPasswordCode'])->middleware('throttle:5,1');
    Route::post('/parametres/mot-de-passe', [ActionController::class, 'changePassword'])->middleware('throttle:10,1');
    Route::post('/parametres/profil', [ActionController::class, 'updateProfile'])->middleware('throttle:20,1');
    // Photos (profil et Espace) : enregistrées tout de suite, sans le bouton « Enregistrer »
    Route::post('/parametres/profil/photo', [ActionController::class, 'updateProfilePhoto'])->middleware('throttle:30,1');
    Route::post('/espaces/{slug}/photo', [ActionController::class, 'updateSpacePhoto'])->middleware('throttle:30,1');
    Route::get('/espaces/{slug}/modifier', [PageController::class, 'editSpace'])->middleware('feature:spaces');
    Route::post('/espaces/{slug}/modifier', [ActionController::class, 'updateSpace'])->middleware('throttle:20,1');
    Route::post('/espaces', [ActionController::class, 'createSpace'])->middleware('feature:spaces');
    Route::post('/espaces/{slug}/rejoindre', [ActionController::class, 'joinSpace']);
    Route::get('/espaces/{slug}/membres', [PageController::class, 'spaceMembers'])->middleware('feature:spaces');
    Route::post('/espaces/{slug}/membres', [ActionController::class, 'spaceAddMember'])->middleware('throttle:30,1');
    Route::post('/espaces/{slug}/invitation', [ActionController::class, 'spaceInviteAnswer']);
    Route::post('/espaces/{slug}/membres/{userId}/decision', [ActionController::class, 'spaceMemberDecision'])->whereNumber('userId');
    Route::post('/espaces/{slug}/membres/{userId}/retirer', [ActionController::class, 'spaceRemoveMember'])->whereNumber('userId');
    Route::post('/espaces/{slug}/membres/{userId}/role', [ActionController::class, 'spaceSetRole'])->whereNumber('userId');
});
