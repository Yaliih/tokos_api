<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Invitations par numéro : on défie quelqu'un qui n'a pas encore de compte (SMS ou WhatsApp).
 *   À son inscription avec ce numéro, l'invitation devient une invitation normale.
 * - Nombre de vues des duels.
 * - Couverture d'une compétition : image ou vidéo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_phone_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('phone_code', 8)->default('+243');
            $table->string('phone_number', 20);
            $table->string('message', 280)->nullable();
            $table->string('token', 40)->unique();
            $table->string('status', 20)->default('pending'); // pending | claimed | expired
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sms_sent_at')->nullable();
            $table->timestamps();
            $table->index(['phone_code', 'phone_number', 'status']);
        });

        Schema::table('competition_matches', function (Blueprint $table) {
            $table->unsignedInteger('views_count')->default(0);
        });

        Schema::table('competitions', function (Blueprint $table) {
            $table->string('cover_type', 10)->nullable(); // image | video
        });
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('cover_type'));
        Schema::table('competition_matches', fn (Blueprint $t) => $t->dropColumn('views_count'));
        Schema::dropIfExists('competition_phone_invites');
    }
};
