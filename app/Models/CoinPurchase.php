<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Achat d'un pack de coins. Les coins sont crédités quand le paiement est confirmé. */
class CoinPurchase extends Model
{
    protected $fillable = ['user_id', 'pack', 'coins', 'price', 'currency', 'provider', 'status', 'provider_ref', 'phone', 'operator', 'paid_at'];

    protected $casts = ['paid_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
