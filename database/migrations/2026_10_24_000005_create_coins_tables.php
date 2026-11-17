<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les coins : achetés en packs, offerts en cadeau, dépensés pour voter dans les compétitions payantes.
 *   users.coins              solde (toujours égal à la somme de coin_transactions)
 *   coin_transactions        journal : chaque mouvement, positif ou négatif, avec le solde après
 *   coin_purchases           achats de packs (paiement mobile money, carte…)
 *   competitions.vote_cost   prix d'un vote en coins (0 = gratuit)
 *   competition_votes.quantity / coins_spent : plusieurs votes payés pour le même camp
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'coins')) {
            Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('coins')->default(0));
        }
        if (! Schema::hasColumn('competitions', 'vote_cost')) {
            Schema::table('competitions', fn (Blueprint $t) => $t->unsignedInteger('vote_cost')->default(0));
        }
        if (! Schema::hasColumn('competition_votes', 'quantity')) {
            Schema::table('competition_votes', function (Blueprint $t) {
                $t->unsignedInteger('quantity')->default(1);
                $t->unsignedBigInteger('coins_spent')->default(0);
            });
        }
        if (! Schema::hasTable('coin_transactions')) {
            Schema::create('coin_transactions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->bigInteger('amount'); // + crédit, - débit
                $t->unsignedBigInteger('balance_after');
                $t->string('type', 30); // purchase | vote | vote_income | gift_sent | gift_received | bonus | refund | adjustment
                $t->string('description', 255)->nullable();
                $t->nullableMorphs('related');
                $t->foreignId('counterparty_id')->nullable()->constrained('users')->nullOnDelete();
                $t->json('meta')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'created_at']);
            });
        }
        if (! Schema::hasTable('coin_purchases')) {
            Schema::create('coin_purchases', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('pack', 30);
                $t->unsignedInteger('coins');
                $t->unsignedInteger('price');
                $t->string('currency', 8);
                $t->string('provider', 30);
                $t->string('status', 20)->default('pending'); // pending | paid | failed | cancelled
                $t->string('provider_ref', 120)->nullable();
                $t->string('phone', 30)->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_purchases');
        Schema::dropIfExists('coin_transactions');
        if (Schema::hasColumn('competition_votes', 'quantity')) {
            Schema::table('competition_votes', fn (Blueprint $t) => $t->dropColumn(['quantity', 'coins_spent']));
        }
        if (Schema::hasColumn('competitions', 'vote_cost')) {
            Schema::table('competitions', fn (Blueprint $t) => $t->dropColumn('vote_cost'));
        }
        if (Schema::hasColumn('users', 'coins')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('coins'));
        }
    }
};
