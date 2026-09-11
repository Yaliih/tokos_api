<?php
// app/Models/PostMedia.php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'type',
        'url',
        'thumbnail_url',
        'order',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'order' => 'integer',
    ];

    protected $appends = ['url_full'];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    // ⭐ Accesseur pour l'URL complète
    public function getUrlFullAttribute(): string
    {
        return $this->getMediaUrl($this->url);
    }

    // ⭐ Accesseur pour la vignette
    public function getThumbnailFullAttribute(): ?string
    {
        if (!$this->thumbnail_url) return null;
        return $this->getMediaUrl($this->thumbnail_url);
    }

    // ⭐ Méthode pour générer l'URL d'un média
    private function getMediaUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        return route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&', $path)));
    }
}