<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les anciens pseudos : réservés pour toujours à la même personne (personne d'autre ne peut les prendre)
 * et redirigés vers son profil actuel. Le pseudo ne se change qu'une seule fois (le premier est créé à l'inscription).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('username_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username', 60)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('username_history');
    }
};
