<?php

/*
|--------------------------------------------------------------------------
| Moteur de compétition unifié
|--------------------------------------------------------------------------
|
| Une seule mécanique pour : défi (challenge), duel, concours (contest)
| et tournoi (tournament).
|
|  competitions            → la compétition (règles, dates, statut)
|  competition_entries     → les participations (User, Espace, ou libellé libre)
|  competition_matches     → les affrontements 1 contre 1 (duels, tableau de tournoi)
|  competition_votes       → un vote = une ligne, unicité garantie par la base
|  competition_invitations → défis ciblés, compétitions sur invitation
|  competition_jurors      → membres du jury
|  competition_jury_scores → notes du jury (concours)
|  entry_proposals         → propositions internes des membres d'un Espace
|
| Les anciennes tables (post_duels, post_challenges, challenge_*, post_contests,
| contest_*, candidate_*) ne sont PAS supprimées par cette migration : elles
| restent en lecture seule et pourront être supprimées plus tard.
|
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();

            // challenge | duel | contest | tournament
            $table->string('type', 20);
            // Pour un duel : "versus" = le créateur fournit les deux côtés (sondage A/B),
            //                "challenge" = le créateur affronte un adversaire invité.
            // Pour un tournoi : "single_elimination".
            $table->string('format', 30)->nullable();

            // Organisateur : un User ou un Space
            $table->morphs('organizer');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            $table->string('category', 60)->nullable();
            $table->string('cover')->nullable();
            $table->json('media_types')->nullable();   // ["text","image","video","audio"]
            $table->string('prize')->nullable();

            // Qui peut participer
            $table->string('participation_mode', 20)->default('individual'); // individual | space | mixed
            $table->string('access', 20)->default('open');                   // open | members | invited
            $table->boolean('requires_approval')->default(false);
            $table->unsignedInteger('max_entries')->nullable();
            $table->unsignedInteger('min_entries')->default(2);

            // Qui peut voter et comment
            $table->string('vote_audience', 20)->default('public');          // public | followers | members | jury
            $table->string('member_vote_policy', 20)->default('reduced');    // allowed | reduced | excluded
            $table->boolean('blind_votes')->default(false);
            $table->unsignedTinyInteger('jury_weight')->default(0);          // 0..100 (% du score final)
            $table->unsignedTinyInteger('votes_per_user')->default(1);       // concours : nb de candidats votables
            $table->unsignedSmallInteger('winners_count')->default(1);       // concours : nb de lauréats

            // Calendrier
            $table->string('status', 20)->default('open');
            // draft | open (inscriptions) | live (votes en cours) | completed | cancelled
            $table->timestamp('registration_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('match_duration_hours')->default(24);
            $table->unsignedSmallInteger('current_round')->default(0);
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('winner_entry_id')->nullable(); // contrainte ajoutée plus bas

            // Compteurs dénormalisés (mis à jour de façon atomique)
            $table->unsignedInteger('entries_count')->default(0);
            $table->unsignedInteger('matches_count')->default(0);
            $table->unsignedInteger('votes_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('hot_score')->default(0);

            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index(['status', 'ends_at']);
            $table->index('category');
            $table->index('hot_score');
        });

        Schema::create('competition_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();

            // Participant : User ou Space (nullable pour un "versus" ou un candidat ajouté par l'organisateur)
            $table->nullableMorphs('participant');
            // Le membre qui a réellement publié (représentant de l'Espace, ou l'utilisateur lui-même)
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('label')->nullable();     // nom affiché si pas de participant (ex. "Messi")
            $table->text('content')->nullable();
            $table->json('media')->nullable();

            $table->string('status', 20)->default('active');
            // pending | active | rejected | withdrawn | eliminated | winner
            $table->string('rejection_reason')->nullable();
            $table->unsignedSmallInteger('seed')->nullable();
            $table->unsignedSmallInteger('rank')->nullable();

            $table->unsignedInteger('votes_count')->default(0);
            $table->decimal('score', 10, 3)->default(0);
            $table->unsignedSmallInteger('wins')->default(0);
            $table->unsignedSmallInteger('losses')->default(0);
            $table->unsignedSmallInteger('draws')->default(0);

            $table->timestamps();

            $table->unique(['competition_id', 'participant_type', 'participant_id'], 'entries_unique_participant');
            $table->index(['competition_id', 'status']);
            $table->index(['competition_id', 'score']);
        });

        Schema::table('competitions', function (Blueprint $table) {
            $table->foreign('winner_entry_id')->references('id')->on('competition_entries')->nullOnDelete();
        });

        Schema::create('competition_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round')->default(1);
            $table->unsignedSmallInteger('position')->default(1);

            $table->foreignId('entry_a_id')->nullable()->constrained('competition_entries')->nullOnDelete();
            $table->foreignId('entry_b_id')->nullable()->constrained('competition_entries')->nullOnDelete();

            $table->string('status', 20)->default('pending'); // pending | voting | completed | cancelled
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->unsignedInteger('votes_a')->default(0);
            $table->unsignedInteger('votes_b')->default(0);
            $table->decimal('weight_a', 12, 3)->default(0);   // somme pondérée du public
            $table->decimal('weight_b', 12, 3)->default(0);
            $table->unsignedInteger('jury_a')->default(0);
            $table->unsignedInteger('jury_b')->default(0);
            $table->decimal('score_a', 6, 2)->nullable();      // score final figé (0..100)
            $table->decimal('score_b', 6, 2)->nullable();

            $table->foreignId('winner_entry_id')->nullable()->constrained('competition_entries')->nullOnDelete();
            $table->boolean('is_draw')->default(false);
            $table->boolean('is_bye')->default(false);
            $table->boolean('ending_soon_notified')->default(false);
            $table->unsignedInteger('hot_score')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['competition_id', 'round', 'position']);
            $table->index(['status', 'ends_at']);
            $table->index('hot_score');
        });

        Schema::create('competition_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('competition_matches')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('competition_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('weight', 4, 2)->default(1);
            $table->boolean('is_jury')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // Un seul vote par personne et par match (match_id non null)
            $table->unique(['match_id', 'user_id'], 'votes_unique_match_user');
            // Concours (vote hors match) : rempli uniquement quand match_id est null,
            // pour garantir un seul vote par personne et par candidat. Dans un tournoi,
            // la même participation apparaît dans plusieurs matchs, d'où cette colonne dédiée.
            $table->unsignedBigInteger('contest_entry_id')->nullable();
            $table->unique(['contest_entry_id', 'user_id'], 'votes_unique_entry_user');
            $table->index(['competition_id', 'user_id']);
            $table->index(['ip_address', 'created_at']);
        });

        Schema::create('competition_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->morphs('invitee'); // User ou Space
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('message', 280)->nullable();
            $table->string('status', 20)->default('pending'); // pending | accepted | declined | expired
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['competition_id', 'invitee_type', 'invitee_id'], 'invitations_unique');
        });

        Schema::create('competition_jurors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['competition_id', 'user_id']);
        });

        Schema::create('competition_jury_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('competition_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('score'); // 0..10
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['entry_id', 'user_id']);
        });

        // Propositions internes d'un Espace : les membres proposent, un gestionnaire choisit.
        Schema::create('entry_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('content')->nullable();
            $table->json('media')->nullable();
            $table->string('status', 20)->default('pending'); // pending | selected | declined
            $table->unsignedInteger('support_count')->default(0);
            $table->timestamps();
            $table->unique(['competition_id', 'space_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_proposals');
        Schema::dropIfExists('competition_jury_scores');
        Schema::dropIfExists('competition_jurors');
        Schema::dropIfExists('competition_invitations');
        Schema::dropIfExists('competition_votes');
        Schema::dropIfExists('competition_matches');
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropForeign(['winner_entry_id']);
        });
        Schema::dropIfExists('competition_entries');
        Schema::dropIfExists('competitions');
    }
};
