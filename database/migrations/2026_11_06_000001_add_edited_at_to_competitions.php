<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Date de la dernière modification par l'organisateur : la publication affiche « modifié ». */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competitions', 'edited_at')) {
            Schema::table('competitions', fn (Blueprint $t) => $t->timestamp('edited_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('edited_at'));
    }
};
