<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centres d'intérêt et ville vérifiée des personnes ; zone géographique des compétitions.
 * Relançable sans risque (colonnes ajoutées seulement si absentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'interests')) {
                $t->json('interests')->nullable();                 // clés de config('competition.categories')
            }
            if (! Schema::hasColumn('users', 'interests_asked_at')) {
                $t->timestamp('interests_asked_at')->nullable();   // dernier rappel affiché
            }
            if (! Schema::hasColumn('users', 'city_located_at')) {
                $t->timestamp('city_located_at')->nullable();      // ville confirmée par la position du téléphone
            }
        });
        Schema::table('competitions', function (Blueprint $t) {
            if (! Schema::hasColumn('competitions', 'geo_city')) {
                $t->string('geo_city', 80)->nullable();            // réservé à une ville
            }
            if (! Schema::hasColumn('competitions', 'geo_scope')) {
                $t->string('geo_scope', 10)->nullable();           // enter | vote | both
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['interests', 'interests_asked_at', 'city_located_at']));
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn(['geo_city', 'geo_scope']));
    }
};
