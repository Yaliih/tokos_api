<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Un commentaire peut viser un duel précis du défi : il s'affiche « Duel : X contre Y », cliquable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_comments', function (Blueprint $table) {
            $table->foreignId('match_id')->nullable()->after('parent_id')->constrained('competition_matches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('post_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('match_id');
        });
    }
};
