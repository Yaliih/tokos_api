<?php
// app/Models/PostDuel.php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostDuel extends Model
{
    use HasFactory;

    protected $fillable = [
        'content',
        'post_id',
        'option_a',
        'option_b',
        'option_a_media',
        'option_b_media',
        'end_date',
        'votes_a',
        'votes_b',
        'total_votes',
    ];

    protected $casts = [
        'option_a_media' => 'array',
        'option_b_media' => 'array',
        'end_date' => 'datetime',
        'votes_a' => 'integer',
        'votes_b' => 'integer',
        'total_votes' => 'integer',
    ];

    protected $appends = [
        'option_a_media_urls',
        'option_b_media_urls',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function votes()
    {
        return $this->morphMany(PostVote::class, 'voteable');
    }

    // ⭐ Accesseur pour les URLs des médias de l'option A
    public function getOptionAMediaUrlsAttribute(): array
    {
        return $this->getMediaUrls($this->option_a_media);
    }

    // ⭐ Accesseur pour les URLs des médias de l'option B
    public function getOptionBMediaUrlsAttribute(): array
    {
        return $this->getMediaUrls($this->option_b_media);
    }

    // ⭐ Méthode générique pour les URLs
private function getMediaUrls(?array $media): array
{
    if (empty($media)) return [];

    $urls = [];
    foreach ($media as $item) {
        if (isset($item['url'])) {
            $entry = [
                'type' => $item['type'] ?? 'image',
                'url'  => $this->getMediaUrl($item['url']),
            ];
            // ⭐ AJOUTER
            if (!empty($item['metadata'])) {
                $entry['metadata'] = $item['metadata'];
            }
            $urls[] = $entry;
        }
    }
    return $urls;
}

    // ⭐ Méthode pour générer l'URL d'un média
    private function getMediaUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        return route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&', $path)));
    }

    // ⭐ Accesseur pour le premier média de l'option A
    public function getOptionAFirstMediaAttribute(): ?array
    {
        $urls = $this->option_a_media_urls;
        return !empty($urls) ? $urls[0] : null;
    }

    // ⭐ Accesseur pour le premier média de l'option B
    public function getOptionBFirstMediaAttribute(): ?array
    {
        $urls = $this->option_b_media_urls;
        return !empty($urls) ? $urls[0] : null;
    }

    public function refreshVotesCount(): void
    {
        $this->votes_a = $this->votes()
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $this->id)
            ->where('value', 'a')
            ->count();
            
        $this->votes_b = $this->votes()
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $this->id)
            ->where('value', 'b')
            ->count();
            
        $this->total_votes = $this->votes_a + $this->votes_b;
        $this->save();
    }

    public function getPercentageAAttribute(): float
    {
        if ($this->total_votes === 0) return 50;
        return round(($this->votes_a / $this->total_votes) * 100, 1);
    }

    public function getPercentageBAttribute(): float
    {
        if ($this->total_votes === 0) return 50;
        return round(($this->votes_b / $this->total_votes) * 100, 1);
    }

    public function getWinnerAttribute(): ?string
    {
        if ($this->votes_a > $this->votes_b) return 'a';
        if ($this->votes_b > $this->votes_a) return 'b';
        return null;
    }

    public function getTimeLeftAttribute(): ?string
    {
        if (!$this->end_date) return null;
        $diff = now()->diff($this->end_date);
        if ($diff->invert) return 'Terminé';
        if ($diff->days > 0) return "{$diff->days}j {$diff->h}h";
        if ($diff->h > 0) return "{$diff->h}h {$diff->i}m";
        return "{$diff->i}m";
    }

    public function isEnded(): bool
    {
        return $this->end_date && now()->greaterThan($this->end_date);
    }
}