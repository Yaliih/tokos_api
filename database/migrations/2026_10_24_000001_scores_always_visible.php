<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les scores sont désormais toujours visibles : on retire l'option « votes à l'aveugle »
 * des compétitions existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('competitions')->where('blind_votes', true)->update(['blind_votes' => false]);
    }

    public function down(): void
    {
    }
};
