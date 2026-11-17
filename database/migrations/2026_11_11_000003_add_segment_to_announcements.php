<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Annonces : envoyer à un groupe (nouveaux inscrits, plus actifs, moins actifs, venus une fois, une ville…)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $t) {
            $t->json('segment')->nullable()->after('user_ids'); // {key, n, city}
        });
    }

    public function down(): void
    {
        Schema::table('announcements', fn (Blueprint $t) => $t->dropColumn('segment'));
    }
};
