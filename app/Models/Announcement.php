<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Annonce de l'admin (voir App\Services\Announcer). */
class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'url', 'audience', 'user_ids', 'segment', 'repeat', 'next_send_at', 'last_sent_at', 'sent_count', 'last_recipients', 'is_active', 'created_by'];

    protected $casts = [
        'user_ids' => 'array',
        'segment' => 'array',
        'next_send_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
