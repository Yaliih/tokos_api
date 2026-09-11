<?php
// database/migrations/xxxx_xx_xx_create_spaces_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('avatar')->nullable();
            $table->string('cover')->nullable();
            $table->enum('cover_type', ['image', 'video'])->default('image');
            $table->text('bio')->nullable();
            $table->integer('trust_score')->default(0);
            $table->string('category')->nullable();
            $table->string('website')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_private')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ⭐ Table des gestionnaires d'espaces
        Schema::create('space_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('role', ['admin', 'editor', 'viewer'])->default('editor');
            $table->timestamp('joined_at')->useCurrent();
            $table->unique(['space_id', 'user_id']);
            $table->timestamps();
        });

        // ⭐ Table des membres d'espaces (followers)
        Schema::create('space_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_accepted')->default(true);
            $table->timestamp('accepted_at')->nullable();
            $table->unique(['space_id', 'user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_members');
        Schema::dropIfExists('space_managers');
        Schema::dropIfExists('spaces');
    }
};