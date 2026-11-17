<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Signalements : un duel, une compétition, un commentaire, une participation… */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_reports')) {
            return;
        }
        Schema::create('content_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->morphs('reportable');
            $t->string('reason', 40);
            $t->text('details')->nullable();
            $t->string('status', 20)->default('pending'); // pending | reviewed | removed | dismissed
            $t->timestamps();
            $t->unique(['user_id', 'reportable_type', 'reportable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};
