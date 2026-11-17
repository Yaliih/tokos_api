<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Demande de retrait : les coins sont retirés du solde dès la demande, rendus si elle est refusée. */
class CoinWithdrawal extends Model
{
    public const OPERATORS = ['mpesa' => 'M-Pesa', 'orange' => 'Orange Money', 'airtel' => 'Airtel Money', 'afrimoney' => 'Afrimoney'];

    protected $fillable = ['user_id', 'coins', 'amount', 'currency', 'operator', 'phone', 'status', 'provider_ref', 'note', 'paid_at'];

    protected $casts = ['paid_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
