<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Les interrupteurs de fonctions repartent de leurs valeurs par défaut à chaque test
        \App\Support\Features::flush();
        // Les paiements sont coupés par défaut (config/features.php) ; les tests les ouvrent pour les vérifier
        foreach (['coins', 'buy_coins_web', 'buy_coins_app', 'paid_votes'] as $k) {
            config(['features.list.'.$k.'.default' => true]);
        }
        \App\Support\Payments::flush();
        \App\Support\Payments::apply();
        // Les disciplines sont dans la base (réglées dans l'admin) : on les relit une fois la base de test prête
        \App\Support\Categories::refresh();
    }
}
