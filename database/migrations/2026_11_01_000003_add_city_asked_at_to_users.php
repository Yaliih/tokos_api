<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** La ville se demande à part (pas en même temps que les centres d'intérêt). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'city_asked_at')) {
            Schema::table('users', fn (Blueprint $t) => $t->timestamp('city_asked_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('city_asked_at'));
    }
};
