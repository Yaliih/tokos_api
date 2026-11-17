<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - pulse : ce que la plateforme a déjà raconté d'une compétition (premier actuel, classement précédent,
 *   paliers déjà annoncés…), pour les publications automatiques du fil et les alertes aux participants ;
 * - prize_proof : la remise du prix filmée (vidéo ou photo + un mot), dernière étape de la compétition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->json('pulse')->nullable();
            $table->json('prize_proof')->nullable();
            $table->timestamp('prize_proof_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn(['pulse', 'prize_proof', 'prize_proof_at']);
        });
    }
};
