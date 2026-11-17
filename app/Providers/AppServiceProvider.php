<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Moyen de paiement des coins (config/coins.php, COINS_PROVIDER)
        $this->app->bind(\App\Services\Coins\PaymentProvider::class, fn ($app) => match (config('coins.provider')) {
            // Paiement de test (coins gratuits) : jamais en production, même si COINS_PROVIDER est resté sur « test »
            'saspay' => $app->make(\App\Services\Coins\SasPayPaymentProvider::class),
            'test' => $app->environment('production') ? $app->make(\App\Services\Coins\PendingPaymentProvider::class) : $app->make(\App\Services\Coins\TestPaymentProvider::class),
            default => $app->make(\App\Services\Coins\PendingPaymentProvider::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stockage des médias choisi dans l'admin (serveur ou Cloudflare R2)
        \App\Support\MediaStorage::apply();
        // Disciplines réglées dans l'admin (dans la base). Relues après chaque migration (la liste de départ y est posée).
        \App\Support\Categories::apply();
        // Règles réglées dans l'admin (durées des médias, poids, participants…)
        \App\Support\Rules::apply();
        // Paiements (clés SasPay, prix du coin, packs) réglés dans l'admin
        \App\Support\Payments::apply();
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\MigrationsEnded::class, fn () => \App\Support\Categories::refresh());

        // Un défi reçu par SMS ou WhatsApp attend la personne à son inscription
        \App\Models\User::created(function (\App\Models\User $user) {
            try {
                app(\App\Services\Competition\CompetitionService::class)->claimPhoneInvites($user);
            } catch (\Throwable $e) {
                report($e);
            }
            // Coins offerts à l'inscription (config coins.welcome_bonus)
            if (($bonus = (int) config('coins.welcome_bonus')) > 0) {
                try {
                    app(\App\Services\Coins\CoinService::class)->credit($user, $bonus, 'bonus', 'Bienvenue sur Sosa');
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
        // Icônes SVG du site web : @icon('home') ou @icon('heart', 'ico-lg')
        // @feature('coins') … @endfeature : une partie qui disparaît quand la fonction est cachée dans l'admin
        Blade::if('feature', fn (string $f) => \App\Support\Features::on($f));
        Blade::directive('icon', fn (string $expression) => "<?php echo \\App\\Support\\Icons::svg({$expression}); ?>");
    }
}
