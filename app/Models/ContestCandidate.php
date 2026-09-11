<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContestCandidate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'post_id', 'user_id', 'name', 'description', 'media', 'order',
        'status', 'score', 'total_votes',
    ];

    protected $casts = [
        'media' => 'array', 'order' => 'integer', 'score' => 'integer', 'total_votes' => 'integer',
    ];

    protected $appends = ['media_urls', 'votes_count'];

    public function post() { return $this->belongsTo(Post::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function votes() { return $this->hasMany(CandidateVote::class, 'candidate_id'); }
    public function candidatePosts() { return $this->hasMany(CandidatePost::class, 'candidate_id'); }
    public function posts() { return $this->belongsToMany(Post::class, 'candidate_posts', 'candidate_id', 'post_id'); }

    public function getMediaUrlsAttribute(): array
    {
        return collect($this->media ?? [])->map(function ($item) {
            $path = is_array($item) ? ($item['url'] ?? $item['path'] ?? null) : $item;
            if (!$path) return null;
            if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
            return route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&', $path)));
        })->filter()->values()->all();
    }

    public function getVotesCountAttribute(): int { return (int) $this->votes()->sum('value'); }
    public function hasUserVoted(int $userId): bool { return $this->votes()->where('user_id', $userId)->exists(); }
    public function updateTotalVotes(): void { $this->update(['total_votes' => $this->votes_count]); }
}
