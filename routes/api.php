<?php
// routes/api.php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChallengeController;
use App\Http\Controllers\Api\ContestController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\GetMediaController;
use App\Http\Controllers\Api\ParticipationController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SpaceContestAdminController;
use App\Http\Controllers\Api\SpaceContestCandidateController;
use App\Http\Controllers\Api\SpaceController;
use App\Http\Controllers\Api\SpaceManagerController;
use App\Http\Controllers\Api\SpacePostController;
use App\Http\Controllers\Api\SpaceStatisticsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentification publique — Web SPA
|--------------------------------------------------------------------------
|
| Ces routes utilisent la session Laravel.
| Elles ne retournent aucun token Bearer.
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [
        AuthController::class,
        'webRegister',
    ]);

    Route::post('/login', [
        AuthController::class,
        'webLogin',
    ])->name('login');

    Route::post('/send-verification-code', [
        AuthController::class,
        'sendVerificationCode',
    ]);

    Route::post('/resend-verification-code', [
        AuthController::class,
        'resendVerificationCode',
    ]);

    Route::post('/reset-password', [
        AuthController::class,
        'resetPassword',
    ]);

    Route::get('/preferences', [
        AuthController::class,
        'preferences',
    ]);
});

/*
|--------------------------------------------------------------------------
| Authentification publique — Application mobile
|--------------------------------------------------------------------------
|
| Ces routes retournent un token Sanctum.
|
*/

Route::prefix('mobile/auth')->group(function () {
    Route::post('/login', [
        AuthController::class,
        'mobileLogin',
    ]);

    Route::post('/register', [
        AuthController::class,
        'mobileRegister',
    ]);
});

/*
|--------------------------------------------------------------------------
| Routes publiques
|--------------------------------------------------------------------------
*/

Route::get('/get-media/{path}', [
    GetMediaController::class,
    'file',
])
    ->where('path', '.*')
    ->name('media');

/*
|--------------------------------------------------------------------------
| Espaces publics
|--------------------------------------------------------------------------
|
| Ces routes doivent être placées hors du groupe auth:sanctum
| pour être réellement accessibles sans connexion.
|
*/

Route::prefix('spaces')->group(function () {
    // IMPORTANT : toutes les routes statiques doivent rester avant /{slug}.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/mine', [
            SpaceController::class,
            'mine',
        ]);

        Route::get('/discover', [
            SpaceController::class,
            'discover',
        ]);
    });

    Route::get('/{slug}', [
        SpaceController::class,
        'show',
    ]);

    Route::get('/{slug}/posts', [
        SpacePostController::class,
        'index',
    ]);

    Route::get('/{slug}/members', [
        SpaceController::class,
        'members',
    ]);

    Route::get('/{slug}/activities', [
        SpaceController::class,
        'activities',
    ]);
});

/*
|--------------------------------------------------------------------------
| Routes protégées
|--------------------------------------------------------------------------
|
| auth:sanctum accepte :
| - la session de la SPA Next.js ;
| - le Bearer token de l'application mobile.
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentification
    |--------------------------------------------------------------------------
    */

    Route::prefix('auth')->group(function () {
        Route::get('/user', [
            AuthController::class,
            'user',
        ]);

        Route::post('/logout', [
            AuthController::class,
            'webLogout',
        ]);

        Route::post('/verify-phone', [
            AuthController::class,
            'verifyPhone',
        ]);

        Route::put('/profile', [
            AuthController::class,
            'updateProfile',
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Déconnexion mobile
    |--------------------------------------------------------------------------
    */

    Route::post('/mobile/auth/logout', [
        AuthController::class,
        'mobileLogout',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Utilisateurs
    |--------------------------------------------------------------------------
    */

    Route::get('/users/search', [
        UserController::class,
        'search',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Profil
    |--------------------------------------------------------------------------
    */

    Route::prefix('profile')->group(function () {
        Route::get('/{username}', [
            ProfileController::class,
            'show',
        ]);

        Route::put('/', [
            ProfileController::class,
            'update',
        ]);

        Route::post('/avatar', [
            ProfileController::class,
            'updateAvatar',
        ]);

        Route::post('/cover', [
            ProfileController::class,
            'updateCover',
        ]);

        Route::delete('/avatar', [
            ProfileController::class,
            'deleteAvatar',
        ]);

        Route::delete('/cover', [
            ProfileController::class,
            'deleteCover',
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Follow
    |--------------------------------------------------------------------------
    */

    Route::prefix('follow')->group(function () {
        Route::post('/{username}', [
            FollowController::class,
            'follow',
        ]);

        Route::delete('/{username}', [
            FollowController::class,
            'unfollow',
        ]);

        Route::get('/followers/{username}', [
            FollowController::class,
            'followers',
        ]);

        Route::get('/following/{username}', [
            FollowController::class,
            'following',
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Gestion protégée des espaces
    |--------------------------------------------------------------------------
    */

    Route::prefix('spaces')->group(function () {
        // Conservé temporairement pour les anciens clients.
        Route::get('/', [
            SpaceController::class,
            'index',
        ]);

        Route::post('/', [
            SpaceController::class,
            'store',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Gestion modulaire d'un espace
        |--------------------------------------------------------------------------
        |
        | Chaque onglet possède son propre endpoint :
        | - statistiques ;
        | - concours ;
        | - candidatures ;
        | - candidats.
        |
        */

        Route::get('/{slug}/statistics', [
            SpaceStatisticsController::class,
            'show',
        ]);

        Route::get('/{slug}/contests', [
            SpaceContestAdminController::class,
            'index',
        ]);

        Route::get('/{slug}/contests/{contestId}', [
            SpaceContestAdminController::class,
            'show',
        ])->whereNumber('contestId');

        Route::put('/{slug}/contests/{contestId}', [
            SpaceContestAdminController::class,
            'update',
        ])->whereNumber('contestId');

        Route::delete('/{slug}/contests/{contestId}', [
            SpaceContestAdminController::class,
            'destroy',
        ])->whereNumber('contestId');

        Route::post('/{slug}/contests/{contestId}/cancel', [
            SpaceContestAdminController::class,
            'cancel',
        ])->whereNumber('contestId');

        Route::get('/{slug}/contests/{contestId}/applications', [
            SpaceContestCandidateController::class,
            'applications',
        ])->whereNumber('contestId');


        Route::post('/{slug}/contests/{contestId}/applications/{applicationId}/approve', [
            SpaceContestCandidateController::class,
            'approve',
        ])
            ->whereNumber('contestId')
            ->whereNumber('applicationId');

        Route::post('/{slug}/contests/{contestId}/applications/{applicationId}/reject', [
            SpaceContestCandidateController::class,
            'reject',
        ])
            ->whereNumber('contestId')
            ->whereNumber('applicationId');

        Route::post('/{slug}/contests/{contestId}/applications/{applicationId}/cancel', [
            SpaceContestCandidateController::class,
            'cancel',
        ])
            ->whereNumber('contestId')
            ->whereNumber('applicationId');

        Route::get('/{slug}/contests/{contestId}/candidates', [
            SpaceContestCandidateController::class,
            'index',
        ])->whereNumber('contestId');

        Route::post('/{slug}/contests/{contestId}/candidates', [
            SpaceContestCandidateController::class,
            'store',
        ])->whereNumber('contestId');

        Route::put('/{slug}/contests/{contestId}/candidates/{candidateId}', [
            SpaceContestCandidateController::class,
            'update',
        ])
            ->whereNumber('contestId')
            ->whereNumber('candidateId');

        Route::delete('/{slug}/contests/{contestId}/candidates/{candidateId}', [
            SpaceContestCandidateController::class,
            'destroy',
        ])
            ->whereNumber('contestId')
            ->whereNumber('candidateId');

        Route::put('/{slug}', [
            SpaceController::class,
            'update',
        ]);

        Route::delete('/{slug}', [
            SpaceController::class,
            'destroy',
        ]);

        Route::post('/{slug}/avatar', [
            SpaceController::class,
            'updateAvatar',
        ]);

        Route::delete('/{slug}/avatar', [
            SpaceController::class,
            'deleteAvatar',
        ]);

        Route::post('/{slug}/cover', [
            SpaceController::class,
            'updateCover',
        ]);

        Route::delete('/{slug}/cover', [
            SpaceController::class,
            'deleteCover',
        ]);

        Route::get('/{slug}/managers', [
            SpaceManagerController::class,
            'index',
        ]);

        Route::post('/{slug}/managers', [
            SpaceManagerController::class,
            'addManager',
        ]);

        Route::put('/{slug}/managers/{managerId}', [
            SpaceManagerController::class,
            'updateManager',
        ]);

        Route::delete('/{slug}/managers/{managerId}', [
            SpaceManagerController::class,
            'removeManager',
        ]);

        Route::post('/{slug}/join', [
            SpaceController::class,
            'join',
        ]);

        Route::delete('/{slug}/leave', [
            SpaceController::class,
            'leave',
        ]);

        Route::post('/{slug}/posts', [
            SpacePostController::class,
            'store',
        ]);

        Route::put('/{slug}/posts/{postSlug}', [
            SpacePostController::class,
            'update',
        ]);

        Route::delete('/{slug}/posts/{postSlug}', [
            SpacePostController::class,
            'destroy',
        ]);

        Route::post('/{slug}/posts/{postSlug}/like', [
            SpacePostController::class,
            'like',
        ]);

        Route::post('/{slug}/posts/{postSlug}/comments', [
            SpacePostController::class,
            'comment',
        ]);

        Route::delete('/{slug}/posts/{postSlug}/comments/{commentId}', [
            SpacePostController::class,
            'deleteComment',
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Publications
    |--------------------------------------------------------------------------
    |
    | ⭐ SEUL LE CHARGEMENT DU POST UTILISE LE SLUG
    | Toutes les autres routes utilisent l'ID
    |
    | GET /api/posts/feed
    | GET /api/posts/feed/reels
    |
    */

    Route::prefix('posts')->group(function () {
        // Feed
        Route::get('/feed', [
            PostController::class,
            'feed',
        ]);

        Route::get('/feed/reels', [
            PostController::class,
            'reels',
        ]);

        Route::post('/feed/load-more', [
            PostController::class,
            'loadMore',
        ]);

        // CRUD
        Route::get('/', [
            PostController::class,
            'index',
        ]);

        Route::post('/', [
            PostController::class,
            'store',
        ]);

        // Commentaires - routes fixes
        Route::post('/comments/{commentId}/reply', [
            PostController::class,
            'replyComment',
        ]);

        Route::post('/comments/{commentId}/like', [
            PostController::class,
            'likeComment',
        ]);

        Route::delete('/comments/{commentId}', [
            PostController::class,
            'deleteComment',
        ]);

        // ⭐ SEUL GET /{slug} UTILISE LE SLUG
        Route::get('/{slug}', [
            PostController::class,
            'showBySlug',
        ]);

        // ⭐ TOUTES LES AUTRES ROUTES UTILISENT L'ID
        Route::put('/{id}', [
            PostController::class,
            'update',
        ])->whereNumber('id');

        Route::delete('/{id}', [
            PostController::class,
            'destroy',
        ])->whereNumber('id');

        Route::post('/{id}/like', [
            PostController::class,
            'like',
        ])->whereNumber('id');

        Route::post('/{id}/view', [
            PostController::class,
            'markAsViewed',
        ])->whereNumber('id');

        Route::post('/{id}/bookmark', [
            PostController::class,
            'toggleBookmark',
        ])->whereNumber('id');

        Route::post('/{id}/share', [
            PostController::class,
            'share',
        ])->whereNumber('id');

        Route::post('/{id}/report', [
            PostController::class,
            'report',
        ])->whereNumber('id');

        Route::get('/{id}/comments', [
            PostController::class,
            'getComments',
        ])->whereNumber('id');

        Route::post('/{id}/comments', [
            PostController::class,
            'comment',
        ])->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | ⭐ CHALLENGES - TOUTES LES ROUTES UTILISENT L'ID
    |--------------------------------------------------------------------------
    |
    | Seul GET /api/posts/{slug} utilise le slug pour charger le post
    | Toutes les routes challenges utilisent l'ID du post
    |
    */

    Route::prefix('challenges')->group(function () {
        // Récupérer les participants d'un challenge (utilise POST.ID)
        Route::get('/{postId}/participants', [
            ChallengeController::class,
            'getParticipants',
        ])->whereNumber('postId');

        // Récupérer les duels d'un challenge (utilise POST.ID)
        Route::get('/{postId}/duels', [
            ChallengeController::class,
            'getDuels',
        ])->whereNumber('postId');

        // Rejoindre un challenge (utilise POST.ID)
        Route::post('/{postId}/join', [
            ChallengeController::class,
            'join',
        ])->whereNumber('postId');

        // Quitter un challenge (utilise POST.ID)
        Route::delete('/{postId}/leave', [
            ChallengeController::class,
            'leave',
        ])->whereNumber('postId');

        // Récupérer les détails d'un participant
        Route::get('/participants/{participantId}', [
            ChallengeController::class,
            'getParticipant',
        ])->whereNumber('participantId');

        // Récupérer les détails d'un duel
        Route::get('/duels/{duelId}', [
            ChallengeController::class,
            'getDuel',
        ])->whereNumber('duelId');
    });

    /*
    |--------------------------------------------------------------------------
    | ⭐ CONTESTS - TOUTES LES ROUTES UTILISENT L'ID
    |--------------------------------------------------------------------------
    */

    Route::prefix('contests')->group(function () {
        // Récupérer les candidats d'un concours (utilise POST.ID)
        Route::get('/{postId}/candidates', [
            ContestController::class,
            'getCandidates',
        ])->whereNumber('postId');

        // Postuler à un concours (utilise POST.ID)
        Route::post('/{postId}/apply', [
            ContestController::class,
            'apply',
        ])->whereNumber('postId');

        // Récupérer les détails d'un candidat
        Route::get('/candidates/{candidateId}', [
            ContestController::class,
            'getCandidate',
        ])->whereNumber('candidateId');

        // Voter pour un candidat
        Route::post('/candidates/{candidateId}/vote', [
            ContestController::class,
            'voteCandidate',
        ])->whereNumber('candidateId');

        // Récupérer les participants d'un concours (utilise POST.ID)
        Route::get('/{postId}/participants', [
            ContestController::class,
            'getParticipants',
        ])->whereNumber('postId');

        // Récupérer les résultats d'un concours (utilise POST.ID)
        Route::get('/{postId}/results', [
            ContestController::class,
            'getResults',
        ])->whereNumber('postId');
    });

    

    /*
    |--------------------------------------------------------------------------
    | ⭐ PARTICIPATIONS - TOUTES LES ROUTES UTILISENT L'ID
    |--------------------------------------------------------------------------
    */

    Route::prefix('participations')->group(function () {
        // Récupérer une participation par son ID
        Route::get('/{id}', [
            ParticipationController::class,
            'show',
        ])->whereNumber('id');

        // Récupérer les commentaires d'une participation (utilise POST.ID)
        Route::get('/{postId}/comments', [
            ParticipationController::class,
            'getComments',
        ])->whereNumber('postId');

        // Liker une participation (utilise POST.ID)
        Route::post('/{postId}/like', [
            ParticipationController::class,
            'like',
        ])->whereNumber('postId');

        // Bookmark une participation (utilise POST.ID)
        Route::post('/{postId}/bookmark', [
            ParticipationController::class,
            'bookmark',
        ])->whereNumber('postId');

        // Partager une participation (utilise POST.ID)
        Route::post('/{postId}/share', [
            ParticipationController::class,
            'share',
        ])->whereNumber('postId');
    });

    /*
    |--------------------------------------------------------------------------
    | Votes - TOUTES LES ROUTES UTILISENT L'ID
    |--------------------------------------------------------------------------
    */

    Route::prefix('votes')->group(function () {
        // Duel votes (utilise POST.ID)
        Route::post('/duel/{postId}', [
            VoteController::class,
            'voteDuel',
        ])->whereNumber('postId');

        Route::get('/duel/{postId}', [
            VoteController::class,
            'getDuelVote',
        ])->whereNumber('postId');

        // Debate votes (utilise POST.ID)
        Route::post('/debate/{postId}', [
            VoteController::class,
            'voteDebate',
        ])->whereNumber('postId');

        // Challenge duel votes (utilise DUEL.ID)
        Route::post('/challenge-duel/{duelId}', [
            VoteController::class,
            'voteChallengeDuel',
        ])->whereNumber('duelId');

        Route::get('/challenge-duel/{duelId}', [
            VoteController::class,
            'getChallengeDuelVote',
        ])->whereNumber('duelId');

        // Contest candidate votes (utilise CANDIDATE.ID)
        Route::post('/contest-candidate/{candidateId}', [
            VoteController::class,
            'voteContestCandidate',
        ])->whereNumber('candidateId');

        // Admin route pour rafraîchir tous les compteurs
        Route::post('/refresh', [
            VoteController::class,
            'refreshAll',
        ])->middleware('admin');
    });
});