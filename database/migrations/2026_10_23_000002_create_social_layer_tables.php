<?php

/*
|--------------------------------------------------------------------------
| Couche sociale : ce qui fait "vivre" le réseau
|--------------------------------------------------------------------------
|
|  activities     → fil d'activité ("X a publié un défi", "Y a rejoint l'espace Z"...)
|  notifications  → notifications Laravel (canal database)
|  ratings        → classement ELO par catégorie et par saison (Users ET Espaces)
|  badges         → badges gagnés (Users et Espaces)
|  reactions      → réactions emoji sur n'importe quel contenu (match, participation, post)
|  device_tokens  → jetons push (FCM) pour les notifications mobiles
|
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->morphs('actor');                 // qui (User ou Space)
            $table->string('verb', 50);              // competition.created, space.joined, match.won...
            $table->nullableMorphs('subject');       // sur quoi (Competition, Match, Space, User, Badge...)
            $table->nullableMorphs('target');        // dans quel contexte (Space, Competition)
            $table->json('data')->nullable();        // infos figées pour l'affichage (titres, scores...)
            $table->string('visibility', 20)->default('public'); // public | followers | private
            $table->unsignedSmallInteger('importance')->default(1); // 1 normal, 2 notable, 3 majeur
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_type', 'actor_id', 'created_at']);
            $table->index(['target_type', 'target_id', 'created_at']);
            $table->index(['verb', 'created_at']);
            $table->index('created_at');
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->morphs('rateable');                       // User ou Space
            $table->string('category', 60)->default('all');   // "all" = toutes catégories
            $table->string('season', 20)->default('all');     // "all" = tous temps, sinon "2026-T4"
            $table->unsignedInteger('rating')->default(1000);
            $table->unsignedInteger('peak_rating')->default(1000);
            $table->unsignedInteger('points')->default(0);    // points de saison (victoires, podiums...)
            $table->unsignedInteger('matches')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('draws')->default(0);
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('best_streak')->default(0);
            $table->unsignedInteger('trophies')->default(0);  // concours / tournois gagnés
            $table->timestamps();

            $table->unique(['rateable_type', 'rateable_id', 'category', 'season'], 'ratings_unique');
            $table->index(['category', 'season', 'rating']);
            $table->index(['category', 'season', 'points']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');                // User ou Space
            $table->string('code', 50);             // voir config/badges.php
            $table->json('data')->nullable();
            $table->timestamp('awarded_at')->useCurrent();
            $table->unique(['owner_type', 'owner_id', 'code']);
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('reactable');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);            // 🔥 😂 😮 👏 💯 ❤️
            $table->timestamps();
            $table->unique(['reactable_type', 'reactable_id', 'user_id']);
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 20)->default('android');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // Préférences de notification et dernière activité (pour "en ligne récemment")
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->json('notification_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'notification_settings']);
        });
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('activities');
    }
};
