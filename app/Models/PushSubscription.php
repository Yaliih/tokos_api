<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un navigateur ou téléphone qui accepte les notifications push d'une personne. */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
