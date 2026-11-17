<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Récompenses par place : [{from, to, text, coins}] (ex. 1er : 100 $, 2e : 50 $, 3e à 20e : 200 coins).
 * prize_escrow : coins retirés au créateur à la publication, versés aux gagnants à la fin (ou rendus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $t) {
            if (! Schema::hasColumn('competitions', 'prize_tiers')) {
                $t->json('prize_tiers')->nullable();
            }
            if (! Schema::hasColumn('competitions', 'prize_escrow')) {
                $t->unsignedInteger('prize_escrow')->default(0);
                $t->timestamp('prize_settled_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn(['prize_tiers', 'prize_escrow', 'prize_settled_at']));
    }
};
