<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Une compétition peut être réservée à une ville, ou à tout un pays. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competitions', 'geo_country')) {
            Schema::table('competitions', fn (Blueprint $t) => $t->string('geo_country', 80)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('geo_country'));
    }
};
