<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Un Espace peut être une université ou une école : il apparaît alors dans le classement des universités. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('spaces', 'kind')) {
            Schema::table('spaces', function (Blueprint $t) {
                $t->string('kind', 20)->default('group')->index(); // group, school
            });
        }
    }

    public function down(): void
    {
        Schema::table('spaces', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
