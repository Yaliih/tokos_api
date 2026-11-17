<?php
// database/migrations/xxxx_xx_xx_create_posts_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ⭐ Posts unifiés (utilisateurs + espaces)
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->morphs('authorable');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type')->default('post');
            $table->string('slug')->unique()->nullable();
            $table->text('content')->nullable();
            $table->json('media')->nullable();
            $table->string('status')->default('published');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('type');
            $table->index('status');
            $table->index('slug');
        });

        // ⭐ Media
        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->enum('type', ['image', 'video', 'audio', 'pdf', 'other'])->default('image');
            $table->string('url');
            $table->string('thumbnail_url')->nullable();
            $table->integer('order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'order']);
        });

        // ⭐ Likes
        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unique(['post_id', 'user_id']);
            $table->timestamps();
        });

        // ⭐ Comments
        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('content');
            $table->foreignId('parent_id')->nullable()->constrained('post_comments')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['post_id', 'created_at']);
        });

        // ⭐ Comment Likes
        Schema::create('comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('post_comments')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unique(['comment_id', 'user_id']);
            $table->timestamps();
        });

        // ⭐ Views
        Schema::create('post_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->timestamp('viewed_at')->useCurrent();
            $table->integer('duration')->default(5);
            $table->unique(['user_id', 'post_id']);
            $table->index(['user_id', 'viewed_at']);
        });

        // ⭐ Votes
        Schema::create('post_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('voteable_type');
            $table->unsignedBigInteger('voteable_id');
            $table->string('value')->nullable(); // 'a', 'b', 'for', 'against', ou null
            $table->string('ip_address')->nullable();
            $table->timestamps();
            
            $table->unique(['post_id', 'voteable_type', 'voteable_id', 'user_id'], 'unique_vote');
            $table->index(['voteable_type', 'voteable_id']);
            $table->index('user_id');
        });

        // ⭐ DUEL
        Schema::create('post_duels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->text('content');
            $table->text('option_a')->nullable();
            $table->text('option_b')->nullable();
            $table->json('option_a_media')->nullable();
            $table->json('option_b_media')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->integer('votes_a')->default(0);
            $table->integer('votes_b')->default(0);
            $table->integer('total_votes')->default(0);
            $table->timestamps();
        });

        // ⭐ CHALLENGE
        Schema::create('post_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->text('description')->nullable();
            $table->integer('max_applications')->nullable();
            $table->integer('max_participants')->nullable();
            $table->integer('votes_to_win')->default(10);
            $table->timestamp('end_date')->nullable();
            $table->timestamps();
        });

        // ⭐ CHALLENGE PARTICIPANTS
        Schema::create('challenge_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('content')->nullable();
            $table->json('media')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['challenge_id', 'user_id']);
            $table->index(['challenge_id', 'status']);
        });

        // ⭐ CHALLENGE DUELS
        Schema::create('challenge_duels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('participant_a_id')->constrained('challenge_participants')->onDelete('cascade');
            $table->foreignId('participant_b_id')->constrained('challenge_participants')->onDelete('cascade');
            $table->timestamp('end_date')->nullable();
            $table->integer('votes_a')->default(0);
            $table->integer('votes_b')->default(0);
            $table->integer('total_votes')->default(0);
            $table->string('winner')->nullable(); // 'a', 'b', 'draw'
            $table->timestamps();
            $table->index(['challenge_id', 'created_at']);
            $table->unique(['participant_a_id', 'participant_b_id'], 'unique_challenge_duel_pair');
        });

        // ⭐ DEBATE
        Schema::create('post_debates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->string('topic');
            $table->text('for_argument');
            $table->text('against_argument');
            $table->timestamp('end_date')->nullable();
            $table->integer('votes_for')->default(0);
            $table->integer('votes_against')->default(0);
            $table->integer('total_votes')->default(0);
            $table->timestamps();
        });

        // ⭐ CONTEST
        Schema::create('post_contests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('prize')->nullable();
            $table->string('category')->nullable();
            $table->enum('mode', ['simple', 'complex', 'platform'])->default('simple');
            $table->integer('max_participants')->nullable();
            $table->json('submission_docs')->nullable();
            $table->timestamp('submission_start')->nullable();
            $table->timestamp('submission_end')->nullable();
            $table->timestamp('vote_start_at')->nullable();
            $table->timestamp('vote_end_at')->nullable();
            $table->boolean('has_jury')->default(false);
            $table->integer('jury_percentage')->default(0);
            $table->json('jury_members')->nullable();
            $table->enum('vote_type', ['single', 'multiple'])->default('single');
            $table->decimal('vote_cost', 10, 2)->default(0);
            $table->json('candidates')->nullable();
            $table->json('categories')->nullable();
            $table->json('rounds')->nullable();
            $table->json('platform_rounds')->nullable();
            $table->json('cover_media')->nullable();
            $table->string('space_slug')->nullable();
            $table->timestamps();
            $table->index('category');
            $table->index('space_slug');
        });

        // ⭐ Contest Participants
        Schema::create('contest_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->json('submissions')->nullable();
                        $table->text('decision_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->integer('score')->default(0);
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
            $table->index(['post_id', 'score']);
        });

        // ⭐ Contest Rounds
        Schema::create('contest_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->integer('round_number');
            $table->integer('qualifiers')->default(0);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('jury_active')->default(false);
            $table->json('results')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'round_number']);
        });

        // ⭐ Contest Candidates
        Schema::create('contest_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('media')->nullable();
            $table->integer('order')->default(0);
            $table->string('status')->default('active');
            $table->integer('score')->default(0);
            $table->integer('total_votes')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['post_id', 'order']);
            $table->index('status');
            $table->index('score');
        });

        // ⭐ Candidate Votes
        Schema::create('candidate_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('contest_candidates')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('value')->default(1);
            $table->string('ip_address')->nullable();
            $table->timestamps();
            $table->unique(['candidate_id', 'user_id']);
            $table->index('candidate_id');
        });

        // ⭐ Candidate Comments
        Schema::create('candidate_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('contest_candidates')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('content');
            $table->foreignId('parent_id')->nullable()->constrained('candidate_comments')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['candidate_id', 'created_at']);
        });

        // ⭐ Candidate Comment Likes
        Schema::create('candidate_comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('candidate_comments')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unique(['comment_id', 'user_id']);
            $table->timestamps();
            $table->index('comment_id');
        });

        // ⭐ Candidate Posts
        Schema::create('candidate_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('contest_candidates')->onDelete('cascade');
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['candidate_id', 'post_id']);
            $table->index('post_id');
        });

        // ⭐ Shares
        Schema::create('post_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('platform')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'user_id', 'platform']);
        });

        // ⭐ Reports
        Schema::create('post_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('reason');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
            $table->index('status');
        });

        // ⭐ Bookmarks
        Schema::create('post_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        // ⭐ Interactions
        Schema::create('user_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->enum('type', ['view', 'like', 'comment', 'share', 'bookmark', 'vote']);
            $table->integer('weight')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'type']);
            $table->index(['post_id', 'type']);
            $table->unique(['user_id', 'post_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_interactions');
        Schema::dropIfExists('post_bookmarks');
        Schema::dropIfExists('post_reports');
        Schema::dropIfExists('post_shares');
        Schema::dropIfExists('candidate_posts');
        Schema::dropIfExists('candidate_comment_likes');
        Schema::dropIfExists('candidate_comments');
        Schema::dropIfExists('candidate_votes');
        Schema::dropIfExists('contest_candidates');
        Schema::dropIfExists('contest_rounds');
        Schema::dropIfExists('contest_participants');
        Schema::dropIfExists('post_contests');
        Schema::dropIfExists('post_debates');
        Schema::dropIfExists('challenge_duels');
        Schema::dropIfExists('challenge_participants');
        Schema::dropIfExists('post_challenges');
        Schema::dropIfExists('post_duels');
        Schema::dropIfExists('post_votes');
        Schema::dropIfExists('post_views');
        Schema::dropIfExists('comment_likes');
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_likes');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('posts');
    }
};