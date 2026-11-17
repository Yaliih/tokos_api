<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Une ligne en attente dans space_members est soit une demande (la personne veut entrer),
// soit une invitation (l'Espace l'invite : invited_by = qui l'a invitée). La personne invitée accepte ou refuse.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('space_members', 'invited_by')) {
            Schema::table('space_members', function (Blueprint $t) {
                $t->foreignId('invited_by')->nullable()->after('is_accepted')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('space_members', 'invited_by')) {
            Schema::table('space_members', function (Blueprint $t) {
                $t->dropConstrainedForeignId('invited_by');
            });
        }
    }
};
