<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adhésion à un Espace : ouverte (par défaut) ou sur validation d'un administrateur ou d'un modérateur.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('spaces', 'join_approval')) {
            Schema::table('spaces', function (Blueprint $table) {
                $table->boolean('join_approval')->default(false)->after('is_private');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('spaces', 'join_approval')) {
            Schema::table('spaces', fn (Blueprint $table) => $table->dropColumn('join_approval'));
        }
    }
};
