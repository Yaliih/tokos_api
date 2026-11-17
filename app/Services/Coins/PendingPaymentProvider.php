<?php

namespace App\Services\Coins;

use App\Models\CoinPurchase;

/**
 * En attendant un vrai fournisseur : l'achat reste « en attente »
 * et un administrateur le valide à la main (php artisan coins:valider {id}).
 */
class PendingPaymentProvider implements PaymentProvider
{
    public function start(CoinPurchase $purchase): ?string
    {
        return null;
    }
}
