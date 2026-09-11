<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            
            // Identité
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username')->unique()->nullable();
            
            // Contact
            $table->string('phone_code', 5); // +243, +33...
            $table->string('phone_number', 20); // sans 0
            $table->string('email')->unique()->nullable();
            
            // Vérification téléphone
            $table->string('phone_verification_code', 6)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('phone_verification_expires_at')->nullable();
            
            // Authentification
            $table->string('password');
            $table->rememberToken();
            
            // Profil
            $table->date('birth_date');
            $table->string('avatar')->nullable();
            $table->string('cover')->nullable();
            $table->enum('cover_type', ['image', 'video'])->default('image');
            $table->string('photo')->nullable();
            $table->text('bio')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('website')->nullable();
            
            // Statut
            
            $table->timestamp('verified_at')->nullable();//pour le badge
            $table->boolean('is_private')->default(false);//pour le compte privé
            $table->boolean('is_active')->default(true);//pour le compte actif ou désactivé
            $table->boolean('username_changed')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Index
            $table->unique(['phone_code', 'phone_number']);
            $table->index(['phone_code', 'phone_number']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cover', 'cover_type', 'city', 'website']);
        });
    }
};