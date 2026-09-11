<?php
// app/Models/ContestParticipant.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContestParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'decision_reason',
        'reviewed_at',
        'reviewed_by',
        'post_id',
        'user_id',
        'submissions',
        'status',
        'score',
    ];

    protected $casts = [
        'submissions' => 'array',
        'score' => 'integer',
    ];

    protected $appends = ['submissions_with_urls', 'user_name'];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Transforme un chemin en URL avec le format route('media', ...)
     */
    private function urlt($path)
    {
        if (empty($path)) {
            return null;
        }
        
        // Remplacer le point avant l'extension par une virgule
        // Remplacer les slashes par des &
        $link = preg_replace('/\.(?=[^.]*$)/', ',', $path);
        $link = preg_replace('#/#', '&', $link);
        
        return route('media', $link);
    }

    /**
     * Récupère les submissions avec les URLs des fichiers transformées
     */
    public function getSubmissionsWithUrlsAttribute()
    {
        $submissions = $this->submissions ?? [];
        
        if (empty($submissions)) {
            return [];
        }

        // Si c'est une collection de submissions
        return array_map(function ($submission) {
            // Si le submission a un champ 'files'
            if (isset($submission['files']) && is_array($submission['files'])) {
                $submission['files'] = array_map(function ($file) {
                    if (is_string($file)) {
                        // Si file est un string (chemin)
                        return $this->urlt($file);
                    } elseif (is_array($file) && isset($file['path'])) {
                        // Si file est un tableau avec 'path'
                        $file['url'] = $this->urlt($file['path']);
                        return $file;
                    }
                    return $file;
                }, $submission['files']);
            }
            
            // Si le submission a un champ 'file' (singulier)
            if (isset($submission['file'])) {
                if (is_string($submission['file'])) {
                    $submission['file_url'] = $this->urlt($submission['file']);
                } elseif (is_array($submission['file']) && isset($submission['file']['path'])) {
                    $submission['file']['url'] = $this->urlt($submission['file']['path']);
                }
            }
            
            return $submission;
        }, $submissions);
    }

    /**
     * Accesseur pour récupérer les submissions avec URLs (alias)
     */
    public function getSubmissionsAttribute($value)
    {
        $submissions = is_string($value) ? json_decode($value, true) : $value;
        return $submissions ?? [];
    }

    /**
     * Récupère le nom complet de l'utilisateur
     */
    public function getUserNameAttribute()
    {
        if ($this->relationLoaded('user') && $this->user) {
            return trim($this->user->first_name . ' ' . $this->user->last_name);
        }
        return null;
    }
}