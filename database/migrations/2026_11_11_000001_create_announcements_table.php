<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Annonces de l'admin : à tout le monde ou à une sélection, maintenant, à une date, ou chaque mois (rappel).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->string('title', 80);
            $t->string('body', 300);
            $t->string('url', 255)->nullable();
            $t->string('audience', 20)->default('all'); // all | selected
            $t->json('user_ids')->nullable();
            $t->string('repeat', 20)->default('none');   // none | monthly
            $t->timestamp('next_send_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->unsignedInteger('sent_count')->default(0);       // nombre d'envois
            $t->unsignedInteger('last_recipients')->default(0);  // personnes touchées au dernier envoi
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['is_active', 'next_send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
