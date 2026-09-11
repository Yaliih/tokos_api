<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('category');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('preference_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('preference_id')->constrained()->onDelete('cascade');
            $table->unique(['user_id', 'preference_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preference_user');
        Schema::dropIfExists('preferences');
    }
};