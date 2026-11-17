<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Retraits : les coins gagnés (votes, cadeaux) convertis en argent sur mobile money. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_withdrawals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('coins');
            $t->unsignedInteger('amount');
            $t->string('currency', 8)->default('CDF');
            $t->string('operator', 20);
            $t->string('phone', 30);
            $t->string('status', 12)->default('pending'); // pending, paid, rejected
            $t->string('provider_ref')->nullable();
            $t->string('note')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_withdrawals');
    }
};
