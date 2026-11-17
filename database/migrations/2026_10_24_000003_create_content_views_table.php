<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vues uniques : une personne compte une seule fois par compétition ou par duel,
 * quel que soit le nombre de fois qu'elle l'ouvre (site ou app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_views', function (Blueprint $table) {
            $table->id();
            $table->morphs('viewable');                 // Competition ou CompetitionMatch
            $table->string('viewer_key', 64);           // u:<id> pour un compte, g:<identifiant> pour un visiteur
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['viewable_type', 'viewable_id', 'viewer_key'], 'content_views_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_views');
    }
};
