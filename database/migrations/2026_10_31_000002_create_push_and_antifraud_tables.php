<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notifications push, identifiant d'appareil (anti-triche), université et ville pour les classements. */
return new class extends Migration
{
    public function up(): void
    {
        // Peut être relancée sans risque (si une mise à jour a été interrompue)
        if (! Schema::hasTable('push_subscriptions')) Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('endpoint');
            $t->string('endpoint_hash', 64)->unique();
            $t->string('p256dh', 200);
            $t->string('auth', 60);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });

        if (! Schema::hasColumn('competition_votes', 'device_id')) {
            Schema::table('competition_votes', function (Blueprint $t) {
                $t->string('device_id', 64)->nullable()->index();
                $t->boolean('flagged')->default(false)->index();
                $t->string('flag_reason', 60)->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'signup_device')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('signup_device', 64)->nullable()->index();
                $t->string('signup_ip', 45)->nullable();
            });
        }
        if (! Schema::hasColumn('users', 'school_space_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->foreignId('school_space_id')->nullable()->constrained('spaces')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('school_space_id');
            $t->dropColumn(['signup_device', 'signup_ip']);
        });
        Schema::table('competition_votes', fn (Blueprint $t) => $t->dropColumn(['device_id', 'flagged', 'flag_reason']));
        Schema::dropIfExists('push_subscriptions');
    }
};
