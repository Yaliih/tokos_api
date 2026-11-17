<?php

namespace App\Services\Coins;

use App\Models\CoinPurchase;

/** Paiement de test : les coins sont crédités tout de suite. À ne pas utiliser en production. */
class TestPaymentProvider implements PaymentProvider
{
    public function __construct(private CoinService $coins)
    {
    }

    public function start(CoinPurchase $purchase): ?string
    {
        $this->coins->completePurchase($purchase, 'test-'.$purchase->id);

        return null;
    }
}
