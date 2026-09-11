<?php
// app/Models/ChallengeParticipant.php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChallengeParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'challenge_id',
        'user_id',
        'content',
        'media',
        'status',
    ];

    protected $casts = [
        'media' => 'array',
    ];

    protected $appends = ['media_urls', 'first_media'];

    public function challenge()
    {
        return $this->belongsTo(PostChallenge::class, 'challenge_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function duelsAsA()
    {
        return $this->hasMany(ChallengeDuel::class, 'participant_a_id');
    }

    public function duelsAsB()
    {
        return $this->hasMany(ChallengeDuel::class, 'participant_b_id');
    }

    // ⭐ Accesseur pour les URLs des médias
    public function getMediaUrlsAttribute(): array
    {
        if (empty($this->media)) {
            return [];
        }

        $urls = [];
        foreach ($this->media as $item) {
            if (isset($item['url'])) {
                $urls[] = [
                    'type' => $item['type'] ?? 'image',
                    'url' => $this->getMediaUrl($item['url']),
                ];
            }
        }
        return $urls;
    }

    // ⭐ Accesseur pour le premier média
    public function getFirstMediaAttribute(): ?array
    {
        $urls = $this->media_urls;
        return !empty($urls) ? $urls[0] : null;
    }

    // ⭐ Méthode pour générer l'URL d'un média
    private function getMediaUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        return route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&', $path)));
    }

    public function getTotalVotesAttribute()
    {
        return $this->duelsAsA()->sum('votes_a') + $this->duelsAsB()->sum('votes_b');
    }

    public function getWinsAttribute()
    {
        return $this->duelsAsA()->where('winner', 'a')->count() +
               $this->duelsAsB()->where('winner', 'b')->count();
    }

    public function getLossesAttribute()
    {
        return $this->duelsAsA()->where('winner', 'b')->count() +
               $this->duelsAsB()->where('winner', 'a')->count();
    }
}