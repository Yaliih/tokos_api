<?php

namespace App\Services\Coins;

use App\Models\CoinPurchase;

/**
 * Moyen de paiement pour acheter des coins.
 * Pour la production, créer une classe qui appelle l'API de l'agrégateur choisi
 * (paiement mobile money : M-Pesa, Orange Money, Airtel Money…), puis appelle
 * CoinService::completePurchase() à la confirmation (rappel du fournisseur).
 */
interface PaymentProvider
{
    /**
     * Lance le paiement. Retourne une adresse où envoyer la personne (page de paiement),
     * ou null quand le paiement est déjà confirmé ou se confirme sur le téléphone.
     */
    public function start(CoinPurchase $purchase): ?string;
}
