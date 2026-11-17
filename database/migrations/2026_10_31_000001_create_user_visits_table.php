<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Un jour de visite par personne : sert aux stats (actifs, retours, rétention). */
return new class extends Migration
{
    public function up(): void
    {
        // Peut être relancée sans risque (si une mise à jour a été interrompue)
        if (! Schema::hasTable('user_visits')) Schema::create('user_visits', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->unsignedInteger('hits')->default(1);
            $t->string('source', 10)->default('web'); // web, app
            $t->primary(['user_id', 'day']);
            $t->index('day');
        });

        // Historique de départ : jour d'inscription et dernière connexion connue
        $rows = [];
        foreach (DB::table('users')->select('id', 'created_at', 'last_login_at', 'last_seen_at')->get() as $u) {
            foreach (array_unique(array_filter([$u->created_at, $u->last_login_at, $u->last_seen_at])) as $date) {
                $rows[$u->id.'|'.substr((string) $date, 0, 10)] = ['user_id' => $u->id, 'day' => substr((string) $date, 0, 10), 'hits' => 1, 'source' => 'web'];
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('user_visits')->insertOrIgnore($chunk);
        }
        // « Vu à » : à défaut, la dernière connexion connue
        DB::table('users')->whereNull('last_seen_at')->whereNotNull('last_login_at')->update(['last_seen_at' => DB::raw('last_login_at')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_visits');
    }
};
