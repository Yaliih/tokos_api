<?php
// database/migrations/xxxx_xx_xx_create_verification_codes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone_code', 5);
            $table->string('phone_number', 15);
            $table->string('code', 6);
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->integer('attempts')->default(0);
            $table->timestamps();

            // Index pour les recherches rapides
            $table->index(['phone_code', 'phone_number']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};