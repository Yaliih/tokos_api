<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Un navigateur peut recevoir les annonces sans compte : l'abonnement push n'a plus forcément de propriétaire.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // On garde la colonne facultative (les abonnements sans compte seraient perdus).
    }
};
