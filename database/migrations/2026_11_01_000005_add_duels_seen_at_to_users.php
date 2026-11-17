<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dernière fois que la personne a ouvert l'onglet « Duels » : le badge ne compte que les nouveaux. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'duels_seen_at')) {
            Schema::table('users', fn (Blueprint $t) => $t->timestamp('duels_seen_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('duels_seen_at'));
    }
};
