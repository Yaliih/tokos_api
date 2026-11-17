<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zone d'une compétition choisie par l'organisateur dans une recherche de lieux :
 * son niveau (ville, province, pays), son contour [sud, nord, ouest, est] et le code du pays.
 * geo_city garde le nom affiché de la zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $t) {
            if (! Schema::hasColumn('competitions', 'geo_level')) {
                $t->string('geo_level', 10)->nullable();
            }
            if (! Schema::hasColumn('competitions', 'geo_bbox')) {
                $t->json('geo_bbox')->nullable();
            }
            if (! Schema::hasColumn('competitions', 'geo_country_code')) {
                $t->string('geo_country_code', 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn(['geo_level', 'geo_bbox', 'geo_country_code']));
    }
};
