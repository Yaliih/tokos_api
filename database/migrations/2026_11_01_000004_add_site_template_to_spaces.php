<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Le petit site de chaque Espace (lions.sosa.social) : le modèle choisi par ses responsables. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('spaces', 'site_template')) {
            Schema::table('spaces', fn (Blueprint $t) => $t->string('site_template', 30)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('spaces', fn (Blueprint $t) => $t->dropColumn('site_template'));
    }
};
