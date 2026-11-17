<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\CompetitionController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\SocialController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\GetMediaController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SpaceController;
use App\Http\Controllers\Api\SpaceManagerController;
use App\Http\Controllers\Api\SpacePostController;
use App\Http\Controllers\Api\SpaceStatisticsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AUTHENTIFICATION — WEB (Next.js SPA) + MOBILE (Flutter)
|--------------------------------------------------------------------------
|
| - Next.js utilise la session Laravel (middleware "web").
| - Flutter utilise Sanctum Bearer tokens (pas de middleware "web").
|
| Toutes les routes partagées (verify code, reset, preferences) sont
| exposées DEUX FOIS : une fois pour le web, une fois pour le mobile.
|
*/

/*
|--------------------------------------------------------------------------
| WEB — Next.js SPA
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->middleware('web')->group(function () {

    Route::post('/register', [AuthController::class, 'webRegister'])->middleware('feature:registration');
    Route::post('/login', [AuthController::class, 'webLogin'])->name('login');

    Route::post('/send-verification-code', [AuthController::class, 'sendVerificationCode']);
    Route::post('/resend-verification-code', [AuthController::class, 'resendVerificationCode']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::get('/preferences', [AuthController::class, 'preferences']);
});


/*
|--------------------------------------------------------------------------
| MOBILE — Flutter (Bearer token, pas de session)
|--------------------------------------------------------------------------
*/

Route::prefix('mobile/auth')->group(function () {

    // ----- Authentification -----
    Route::post('/login', [AuthController::class, 'mobileLogin']);
    Route::post('/register', [AuthController::class, 'mobileRegister'])->middleware('feature:registration');

    // ----- Vérification par SMS -----
    Route::post('/send-verification-code', [AuthController::class, 'sendVerificationCode']);
    Route::post('/resend-verification-code', [AuthController::class, 'resendVerificationCode']);

    // ----- Réinitialisation de mot de passe -----
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // ----- Préférences (centres d'intérêt) -----
    Route::get('/preferences', [AuthController::class, 'preferences']);
});


/*
|--------------------------------------------------------------------------
| ROUTES PUBLIQUES
|--------------------------------------------------------------------------
*/
Route::get('/avatars', [AvatarController::class, 'index']);
// ── App mobile : réglages, fil, commentaires, classement, abonnés (lecture publique, jeton facultatif) ──
Route::prefix('app')->group(function () {
    $A = \App\Http\Controllers\Api\AppController::class;
    Route::get('/config', [$A, 'config']);
    Route::post('/forgot-password', [$A, 'forgot'])->middleware('throttle:5,1');
    Route::get('/home', [$A, 'home']);
    Route::get('/stories', [$A, 'stories']);
    Route::get('/profile-competitions/{kind}/{handle}', [$A, 'profileCompetitions'])->whereIn('kind', ['user', 'space']); // page « Compétitions » d'un profil
    Route::get('/suggestions', [$A, 'suggestions']); // « À suivre » (Tout voir), avec ?q= pour chercher
    Route::get('/search', [$A, 'search']); // la loupe du haut de l'app // bulles « Lancer » + duels en cours, en haut de l'accueil
    Route::get('/competitions/{competition}/comments', [$A, 'comments']);
    Route::get('/competitions/{competition}/comment-duels', [$A, 'commentDuels']); // viser un duel dans un commentaire
    Route::get('/leaderboards', [$A, 'leaderboards'])->middleware('feature:leaderboard');
    Route::get('/users/{username}/{list}', [$A, 'follows'])->whereIn('list', ['abonnes', 'abonnements']);
    Route::get('/users/{username}', [$A, 'profile']);
    Route::get('/spaces', [$A, 'spaces']);
    Route::get('/spaces/{slug}', [$A, 'space']);
    Route::middleware('auth:sanctum')->group(function () use ($A) {
        Route::post('/competitions/{competition}/like', [$A, 'like']);
        Route::post('/competitions/{competition}/comments', [$A, 'comment'])->middleware(['throttle:20,1', 'feature:comments']);
        Route::post('/comments/{comment}/like', [$A, 'likeComment'])->whereNumber('comment');
        Route::post('/competitions/{competition}/edit', [$A, 'edit'])->middleware('feature:editing');
        Route::get('/competitions/{competition}/edit', [$A, 'editRules']);
        Route::post('/competitions/{competition}/cancel', [$A, 'cancel']);
        Route::post('/competitions/{competition}/prize-proof', [$A, 'prizeProof'])->middleware('throttle:10,1');
        Route::post('/locate', [$A, 'locate'])->middleware('throttle:12,1');
        Route::get('/places', [$A, 'places'])->middleware('throttle:60,1');
        Route::post('/interests', [$A, 'interests']);
        Route::post('/city', [$A, 'city']);
        Route::post('/report', [$A, 'report'])->middleware('throttle:20,1');
        Route::get('/wallet', [$A, 'wallet'])->middleware('feature:coins');
        Route::get('/inbox', [$A, 'inbox']); // « Reçus »
        Route::post('/inbox/invitations/{invitation}/refuser', [$A, 'inboxDecline'])->whereNumber('invitation');
        Route::post('/inbox/candidatures/{entry}/{decision}', [$A, 'inboxCandidate'])->whereNumber('entry')->whereIn('decision', ['accepter', 'refuser']);
        Route::post('/follow/{username}', [$A, 'follow']);
        Route::post('/spaces/{slug}/join', [$A, 'joinSpace'])->middleware('feature:spaces');
        Route::get('/my-spaces', [$A, 'mySpaces']); // « Mes Espaces » du profil
        Route::post('/password/code', [$A, 'sendPasswordCode'])->middleware('throttle:5,1'); // changer son mot de passe : code par SMS
        Route::post('/password', [$A, 'changePassword'])->middleware('throttle:10,1');
        Route::get('/spaces/{slug}/team', [$A, 'spaceTeam']);
        Route::post('/spaces/{slug}/members', [$A, 'spaceAddMember'])->middleware('throttle:30,1');
        Route::post('/spaces/{slug}/invitation', [$A, 'spaceInviteAnswer']);
        Route::post('/spaces/{slug}/members/{userId}/decision', [$A, 'spaceMemberDecision'])->whereNumber('userId');
        Route::post('/spaces/{slug}/members/{userId}/remove', [$A, 'spaceRemoveMember'])->whereNumber('userId');
        Route::post('/spaces/{slug}/members/{userId}/role', [$A, 'spaceSetRole'])->whereNumber('userId');
        Route::get('/spaces/{slug}/edit', [$A, 'editSpaceData']);
        Route::post('/spaces/{slug}/edit', [$A, 'updateSpace'])->middleware('throttle:30,1');
        Route::post('/spaces', [$A, 'createSpace'])->middleware(['feature:spaces', 'throttle:5,1']);
        Route::post('/profile', [$A, 'updateProfile'])->middleware('throttle:20,1');
        Route::post('/gifts', [$A, 'gift'])->middleware(['throttle:30,1', 'feature:gifts']);
    });
});

// Tâches automatiques déclenchées par cron-job.org (adresse dans l'admin, page « Tâches automatiques »)
Route::get('/cron/{token}', [\App\Http\Controllers\Api\CronController::class, 'run'])->where('token', '[A-Za-z0-9]{20,80}')->middleware('throttle:10,1');

Route::get('/get-media/{path}', [GetMediaController::class, 'file'])
    ->where('path', '.*')
    ->name('media');


/*
|--------------------------------------------------------------------------
| ESPACES PUBLICS
|--------------------------------------------------------------------------
*/

Route::prefix('spaces')->middleware('feature:spaces')->group(function () {

    // Routes nécessitant une authentification
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/mine', [SpaceController::class, 'mine']);
        Route::get('/discover', [SpaceController::class, 'discover']);
    });

    // Routes publiques
    Route::get('/{slug}', [SpaceController::class, 'show']);
    Route::get('/{slug}/posts', [SpacePostController::class, 'index']);
    Route::get('/{slug}/members', [SpaceController::class, 'members']);
    Route::get('/{slug}/activities', [SpaceController::class, 'activities']);
});


/*
|--------------------------------------------------------------------------
| COMPÉTITIONS & SOCIAL — lecture publique
|--------------------------------------------------------------------------
| Accessibles sans connexion (liens partagés) ; si un jeton est fourni,
| la réponse indique en plus ce que l'utilisateur peut faire (voter, participer...).
*/

Route::get('/competitions', [CompetitionController::class, 'index']);
// Rappel de SasPay quand un achat de coins est payé ou refusé (signé, sans session)
Route::post('/payments/webhook/saspay', [\App\Http\Controllers\Web\CoinController::class, 'saspayWebhook'])->middleware('throttle:120,1');
Route::get('/competitions/options', [CompetitionController::class, 'options']);
Route::get('/competitions/{competition}', [CompetitionController::class, 'show']);
Route::get('/competitions/{competition}/entries', [CompetitionController::class, 'entries']);
Route::get('/competitions/{competition}/matches', [CompetitionController::class, 'matches']);
Route::get('/competitions/{competition}/bracket', [CompetitionController::class, 'bracket']);

Route::get('/matches/hot', [MatchController::class, 'hot']);
Route::get('/matches/{match}', [MatchController::class, 'show'])->whereNumber('match');

Route::get('/activity', [SocialController::class, 'feed']);
Route::get('/activity/users/{username}', [SocialController::class, 'userActivity']);
Route::get('/activity/spaces/{slug}', [SocialController::class, 'spaceActivity']);

Route::get('/discover', [SocialController::class, 'discover']);
Route::get('/leaderboards', [SocialController::class, 'leaderboard'])->middleware('feature:leaderboard');
Route::get('/stats/users/{username}', [SocialController::class, 'userStats']);
Route::get('/stats/spaces/{slug}', [SocialController::class, 'spaceStats']);


/*
|--------------------------------------------------------------------------
| ROUTES PROTÉGÉES (auth:sanctum)
|--------------------------------------------------------------------------
|
| auth:sanctum accepte :
| 1. La session SPA Next.js
| 2. Le Bearer token Flutter
|
*/

Route::middleware('auth:sanctum')->group(function () {

    // Suggestions « @… » pour défier ou inviter (personnes et Espaces)
    Route::get('/participants/suggest', [\App\Http\Controllers\Web\ActionController::class, 'suggest'])->middleware('throttle:60,1');

    /*
    |--------------------------------------------------------------------------
    | AUTHENTIFICATION
    |--------------------------------------------------------------------------
    */

    Route::prefix('auth')->group(function () {

        Route::get('/user', [AuthController::class, 'user']);

        // webLogout utilise la session, mais on peut aussi logout en mobile via ce endpoint
        Route::post('/logout', [AuthController::class, 'webLogout'])->middleware('web');

        Route::post('/verify-phone', [AuthController::class, 'verifyPhone']);
        Route::post('/profile', [AuthController::class, 'updateProfile']);
    });

    // Déconnexion mobile (Bearer token)
    Route::post('/mobile/auth/logout', [AuthController::class, 'mobileLogout']);


    /*
    |--------------------------------------------------------------------------
    | UTILISATEURS
    |--------------------------------------------------------------------------
    */

    Route::get('/users/search', [UserController::class, 'search']);


    /*
    |--------------------------------------------------------------------------
    | PROFIL
    |--------------------------------------------------------------------------
    */

    Route::prefix('profile')->group(function () {

        Route::get('/{username}', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::post('/avatar', [ProfileController::class, 'updateAvatar']);
        Route::post('/cover', [ProfileController::class, 'updateCover']);
        Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']);
        Route::delete('/cover', [ProfileController::class, 'deleteCover']);
    });


    /*
    |--------------------------------------------------------------------------
    | FOLLOW
    |--------------------------------------------------------------------------
    */

    Route::prefix('follow')->group(function () {

        Route::post('/{username}', [FollowController::class, 'follow']);
        Route::delete('/{username}', [FollowController::class, 'unfollow']);
        Route::get('/followers/{username}', [FollowController::class, 'followers']);
        Route::get('/following/{username}', [FollowController::class, 'following']);
    });


    /*
    |--------------------------------------------------------------------------
    | ESPACES PROTÉGÉS
    |--------------------------------------------------------------------------
    */

    Route::prefix('spaces')->middleware('feature:spaces')->group(function () {

        Route::get('/', [SpaceController::class, 'index']);
        Route::post('/', [SpaceController::class, 'store']);

        // Statistiques
        Route::get('/{slug}/statistics', [SpaceStatisticsController::class, 'show']);

        // Gestion de l'espace
        Route::put('/{slug}', [SpaceController::class, 'update']);
        Route::delete('/{slug}', [SpaceController::class, 'destroy']);
        Route::post('/{slug}/avatar', [SpaceController::class, 'updateAvatar']);
        Route::delete('/{slug}/avatar', [SpaceController::class, 'deleteAvatar']);
        Route::post('/{slug}/cover', [SpaceController::class, 'updateCover']);
        Route::delete('/{slug}/cover', [SpaceController::class, 'deleteCover']);

        // Managers
        Route::get('/{slug}/managers', [SpaceManagerController::class, 'index']);
        Route::post('/{slug}/managers', [SpaceManagerController::class, 'addManager']);
        Route::put('/{slug}/managers/{managerId}', [SpaceManagerController::class, 'updateManager']);
        Route::delete('/{slug}/managers/{managerId}', [SpaceManagerController::class, 'removeManager']);

        // Membres
        Route::post('/{slug}/join', [SpaceController::class, 'join']);
        Route::delete('/{slug}/leave', [SpaceController::class, 'leave']);

        // Publications d'espace
        Route::post('/{slug}/posts', [SpacePostController::class, 'store']);
        Route::put('/{slug}/posts/{postSlug}', [SpacePostController::class, 'update']);
        Route::delete('/{slug}/posts/{postSlug}', [SpacePostController::class, 'destroy']);
        Route::post('/{slug}/posts/{postSlug}/like', [SpacePostController::class, 'like']);
        Route::post('/{slug}/posts/{postSlug}/comments', [SpacePostController::class, 'comment']);
        Route::delete('/{slug}/posts/{postSlug}/comments/{commentId}', [SpacePostController::class, 'deleteComment']);
    });


    /*
    |--------------------------------------------------------------------------
    | PUBLICATIONS
    |--------------------------------------------------------------------------
    */

    Route::prefix('posts')->group(function () {

        Route::get('/feed', [PostController::class, 'feed']);
        Route::get('/feed/reels', [PostController::class, 'reels']);
        Route::post('/feed/load-more', [PostController::class, 'loadMore']);

        Route::get('/', [PostController::class, 'index']);
        Route::post('/', [PostController::class, 'store']);

        Route::post('/comments/{commentId}/reply', [PostController::class, 'replyComment']);
        Route::post('/comments/{commentId}/like', [PostController::class, 'likeComment']);
        Route::delete('/comments/{commentId}', [PostController::class, 'deleteComment']);

        Route::get('/{slug}', [PostController::class, 'showBySlug']);
        Route::put('/{id}', [PostController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PostController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/like', [PostController::class, 'like'])->whereNumber('id');
        Route::post('/{id}/view', [PostController::class, 'markAsViewed'])->whereNumber('id');
        Route::post('/{id}/bookmark', [PostController::class, 'toggleBookmark'])->whereNumber('id');
        Route::post('/{id}/share', [PostController::class, 'share'])->whereNumber('id');
        Route::post('/{id}/report', [PostController::class, 'report'])->whereNumber('id');

        Route::get('/{id}/comments', [PostController::class, 'getComments'])->whereNumber('id');
        Route::post('/{id}/comments', [PostController::class, 'comment'])->whereNumber('id');
    });


    /*
    |--------------------------------------------------------------------------
    | VOTES (débats uniquement — les duels, défis, concours et tournois
    | passent par le moteur de compétition : voir /competitions et /matches)
    |--------------------------------------------------------------------------
    */

    Route::post('/votes/debate/{postId}', [VoteController::class, 'voteDebate'])->whereNumber('postId');

    /*
    |--------------------------------------------------------------------------
    | COMPÉTITIONS (défis, duels, concours, tournois) — actions connectées
    |--------------------------------------------------------------------------
    */

    Route::prefix('competitions')->group(function () {
        Route::post('/', [CompetitionController::class, 'store']);
        Route::put('/{competition}', [CompetitionController::class, 'update'])->middleware('feature:editing');
        Route::delete('/{competition}', [CompetitionController::class, 'destroy']);
        Route::post('/{competition}/start', [CompetitionController::class, 'start']);

        Route::post('/{competition}/entries', [CompetitionController::class, 'enter'])->middleware('throttle:20,1');
        Route::post('/{competition}/candidates', [CompetitionController::class, 'addCandidate']);
        Route::delete('/{competition}/entries/{entry}', [CompetitionController::class, 'withdraw'])->whereNumber('entry');
        Route::post('/{competition}/entries/{entry}/approve', [CompetitionController::class, 'approve'])->whereNumber('entry');
        Route::post('/{competition}/entries/{entry}/reject', [CompetitionController::class, 'reject'])->whereNumber('entry');
        Route::post('/{competition}/entries/{entry}/vote', [CompetitionController::class, 'voteEntry'])->whereNumber('entry')->middleware('throttle:60,1');
        Route::post('/{competition}/entries/{entry}/jury-score', [CompetitionController::class, 'juryScore'])->whereNumber('entry')->middleware('feature:jury');

        Route::post('/{competition}/invitations', [CompetitionController::class, 'invite']);
        Route::put('/{competition}/jurors', [CompetitionController::class, 'setJurors']);

        Route::get('/{competition}/proposals', [CompetitionController::class, 'proposals']);
        Route::post('/{competition}/proposals', [CompetitionController::class, 'propose'])->middleware('feature:space_competitions');
        Route::post('/{competition}/proposals/{proposal}/select', [CompetitionController::class, 'selectProposal'])->whereNumber('proposal');
    });

    Route::prefix('matches')->group(function () {
        Route::post('/{match}/vote', [MatchController::class, 'vote'])->whereNumber('match')->middleware('throttle:60,1');
        Route::post('/{match}/react', [MatchController::class, 'react'])->whereNumber('match');
        // Tournoi : envoyer sa prestation pour le tour (champs content, media[])
        Route::post('/{match}/submission', function (\Illuminate\Http\Request $r, \App\Models\CompetitionMatch $match) {
            $m = app(\App\Services\Competition\MatchService::class)->submitRound($r->user(), $match, $r->all());

            return response()->json(['success' => true, 'data' => \App\Support\Presenter::match($m->fresh(['competition', 'entryA.participant', 'entryB.participant']), $r->user())]);
        })->whereNumber('match')->middleware('throttle:10,1');
        Route::post('/{match}/rematch', [MatchController::class, 'rematch'])->whereNumber('match')->middleware('feature:rematch');
    });

    Route::get('/invitations', [InvitationController::class, 'index']);
    Route::post('/invitations/{invitation}/accept', [InvitationController::class, 'accept'])->whereNumber('invitation');
    Route::post('/invitations/{invitation}/decline', [InvitationController::class, 'decline'])->whereNumber('invitation');

    /*
    |--------------------------------------------------------------------------
    | NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    Route::get('/notifications', [SocialController::class, 'notifications']);
    Route::get('/notifications/unread-count', [SocialController::class, 'unreadCount']);
    Route::post('/notifications/read', [SocialController::class, 'markRead']);
    Route::put('/notifications/settings', [SocialController::class, 'notificationSettings']);
    Route::post('/device-tokens', [SocialController::class, 'registerDevice']);
    Route::post('/device-tokens/remove', [SocialController::class, 'unregisterDevice']);
});