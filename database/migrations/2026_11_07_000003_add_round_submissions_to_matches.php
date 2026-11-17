<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournoi : à partir du 2e tour, chaque qualifié envoie une nouvelle prestation pour son duel.
 * Le duel attend (status « preparing ») jusqu'à submit_by, délai fixé par la plateforme.
 * forfeit : a | b (ce côté n'a rien envoyé) | both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_matches', function (Blueprint $t) {
            if (! Schema::hasColumn('competition_matches', 'submit_by')) {
                $t->timestamp('submit_by')->nullable();
                foreach (['a', 'b'] as $s) {
                    $t->text($s.'_content')->nullable();
                    $t->json($s.'_media')->nullable();
                    $t->timestamp($s.'_submitted_at')->nullable();
                }
                $t->string('forfeit', 5)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('competition_matches', fn (Blueprint $t) => $t->dropColumn(['submit_by', 'a_content', 'a_media', 'a_submitted_at', 'b_content', 'b_media', 'b_submitted_at', 'forfeit']));
    }
};
