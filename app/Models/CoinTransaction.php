<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un mouvement de coins (positif ou négatif), avec le solde après le mouvement. */
class CoinTransaction extends Model
{
    public const LABELS = [
        'purchase' => 'Achat de coins',
        'vote' => 'Votes',
        'vote_income' => 'Votes reçus',
        'gift_sent' => 'Cadeau offert',
        'gift_received' => 'Cadeau reçu',
        'bonus' => 'Bonus',
        'refund' => 'Remboursement',
        'prize_escrow' => 'Récompense bloquée',
        'prize_won' => 'Récompense gagnée',
        'withdrawal' => 'Retrait',
        'adjustment' => 'Ajustement',
    ];

    /** Mouvements qui comptent comme des gains (retirables). */
    public const EARNINGS = ['vote_income', 'gift_received', 'prize_won'];

    protected $fillable = ['user_id', 'amount', 'balance_after', 'type', 'description', 'related_type', 'related_id', 'counterparty_id', 'meta'];

    protected $casts = ['meta' => 'array', 'amount' => 'integer', 'balance_after' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function counterparty()
    {
        return $this->belongsTo(User::class, 'counterparty_id');
    }

    public function related()
    {
        return $this->morphTo();
    }
}
