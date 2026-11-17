<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Administration : accès admin, réglages (interrupteurs), messages envoyés, demandes de certification, modération. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false)->after('is_active');
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        // Chaque SMS / WhatsApp envoyé (ou raté), avec le canal qui a réussi
        Schema::create('outbound_messages', function (Blueprint $t) {
            $t->id();
            $t->string('to', 30);
            $t->string('purpose', 30)->nullable(); // code, invitation, reset…
            $t->text('body');
            $t->string('channel', 20)->nullable(); // canal qui a réussi
            $t->string('status', 12)->default('pending'); // sent, failed
            $t->json('attempts')->nullable(); // [{channel, ok, error}]
            $t->string('provider_ref')->nullable();
            $t->timestamps();
            $t->index(['to', 'created_at']);
        });

        Schema::create('verification_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('space_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('category', 30);
            $t->string('full_name');
            $t->string('document_type', 30)->nullable();
            $t->string('document_path')->nullable();   // disque privé
            $t->string('selfie_path')->nullable();     // disque privé
            $t->string('proof_path')->nullable();      // disque privé (attestation, statuts…)
            $t->json('links')->nullable();
            $t->text('note')->nullable();
            $t->string('status', 12)->default('pending'); // pending, approved, rejected
            $t->text('admin_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });

        Schema::table('competitions', function (Blueprint $t) {
            $t->timestamp('hidden_at')->nullable(); // masquée par la modération
        });
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('hidden_at'));
        Schema::dropIfExists('verification_requests');
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('settings');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
