<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieux déjà trouvés (recherche de l'organisateur) et positions déjà traduites en ville : on les garde
 * pour ne plus payer le service de cartes deux fois. Et plusieurs zones possibles par compétition.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('places')) {
            Schema::create('places', function (Blueprint $t) {
                $t->id();
                $t->string('uid', 120)->unique();          // identifiant stable (OpenStreetMap, sinon nom + niveau + pays)
                $t->string('label', 160);
                $t->string('context', 190)->nullable();     // « Kongo Central, RD Congo »
                $t->string('level', 12);                    // country | state | county | city | locality
                $t->string('country', 80)->nullable();
                $t->string('country_code', 2)->nullable();
                $t->string('state', 120)->nullable();
                $t->json('bbox');                           // [sud, nord, ouest, est]
                $t->decimal('lat', 9, 5);
                $t->decimal('lng', 9, 5);
                $t->string('search_name', 160)->index();    // nom simplifié pour la recherche locale
                $t->unsignedInteger('hits')->default(0);    // choisi combien de fois
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('place_queries')) {
            Schema::create('place_queries', function (Blueprint $t) {
                $t->id();
                $t->string('q', 120)->unique();
                $t->json('place_ids');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('geo_cells')) {
            Schema::create('geo_cells', function (Blueprint $t) {
                $t->id();
                $t->string('cell', 40)->unique();           // carré d'environ 1 km : « -4.33:15.31 »
                $t->string('city', 120)->nullable();
                $t->string('county', 120)->nullable();
                $t->string('state', 120)->nullable();
                $t->string('country', 80)->nullable();
                $t->string('country_code', 2)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('competitions', 'geo_zones')) {
            Schema::table('competitions', fn (Blueprint $t) => $t->json('geo_zones')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
        Schema::dropIfExists('place_queries');
        Schema::dropIfExists('geo_cells');
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('geo_zones'));
    }
};
