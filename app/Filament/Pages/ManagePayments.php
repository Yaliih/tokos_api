<?php

namespace App\Filament\Pages;

use App\Support\Features;
use App\Support\Payments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Paiements : SasPay (mobile money), prix du coin, packs, commission, retraits,
 * et l'ouverture de l'achat sur le site et dans l'app (séparément, coupés par défaut).
 *
 * @property-read Schema $form
 */
class ManagePayments extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Paiements';

    protected static ?string $title = 'Paiements et coins';

    protected static ?string $slug = 'paiements';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public function mount(): void
    {
        $f = Features::all();
        $this->form->fill(Payments::form() + [
            'coins' => $f['coins'], 'buy_coins_web' => $f['buy_coins_web'], 'buy_coins_app' => $f['buy_coins_app'],
            'paid_votes' => $f['paid_votes'], 'withdrawals' => $f['withdrawals'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Ce qui est ouvert')
                        ->description('Tout est coupé par défaut. Ouvre seulement quand le paiement est prêt.')
                        ->schema([
                            Toggle::make('coins')->label('Coins (interrupteur général)')->helperText('Coupé : plus rien de payant n\'apparaît, nulle part.')->onColor('success')->live(),
                            Toggle::make('buy_coins_web')->label('Achat de coins sur le site')->helperText('Mobile money par SasPay, sur la page Portefeuille du site.')->onColor('success'),
                            Toggle::make('buy_coins_app')->label('Achat de coins dans l\'app')
                                ->helperText('Google Play interdit de faire payer des coins hors de son propre système, même en ouvrant le site depuis l\'app. Laisse coupé tant que Google Play Billing n\'est pas branché (ou pour une app installée hors Play Store).')
                                ->onColor('warning'),
                            Toggle::make('paid_votes')->label('Votes payants')->onColor('success'),
                            Toggle::make('withdrawals')->label('Retraits en argent')->helperText('Seulement avec un partenaire agréé et l\'avis d\'un juriste.')->onColor('danger'),
                        ])->columns(2),

                    Section::make('SasPay (mobile money)')
                        ->description('M-Pesa, Orange Money et Airtel Money, en francs congolais. Une clé sk_test_… = mode test, aucun argent réel.')
                        ->schema([
                            Text::make(fn () => Payments::form()['has_key']
                                ? (Payments::form()['test_key'] ? 'Clé enregistrée : mode TEST (aucun argent réel).' : 'Clé enregistrée : mode RÉEL.')
                                : 'Aucune clé : sans clé, un achat reste en attente (en production).'),
                            Text::make(fn () => 'Adresse du webhook à coller dans le tableau de bord SasPay : '.url('/api/payments/webhook/saspay')),
                            TextInput::make('saspay_key')->label('Clé API')->password()->revealable()->placeholder('Laisse vide pour garder la clé actuelle'),
                            TextInput::make('saspay_secret')->label('Secret du webhook')->password()->revealable()
                                ->placeholder(fn () => Payments::form()['has_secret'] ? 'Enregistré · laisse vide pour le garder' : 'Pas encore enregistré'),
                        ])->columns(2),

                    Section::make('Prix des coins')
                        ->schema([
                            TextInput::make('coin_price')->label('Prix d\'un coin (FC)')->numeric()->integer()->minValue(1)->required(),
                            TextInput::make('pack_sizes')->label('Packs proposés (nombre de coins)')->helperText('Séparés par des virgules. Ex. 50, 150, 400, 1000. Prix = coins × prix d\'un coin.')->required(),
                            TextInput::make('popular')->label('Pack mis en avant (« Populaire »)')->numeric()->integer(),
                            TextInput::make('commission')->label('Part de la plateforme (%)')->helperText('Prélevée sur les votes payants et les cadeaux reçus.')->numeric()->integer()->minValue(0)->maxValue(90),
                        ])->columns(2),

                    Section::make('Retraits')
                        ->schema([
                            TextInput::make('cashout_rate')->label('FC versés par coin')->numeric()->integer()->minValue(0),
                            TextInput::make('cashout_min')->label('Retrait minimum (coins)')->numeric()->integer()->minValue(1),
                        ])->columns(2),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Enregistrer')->submit('save')->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $v = $this->form->getState();
        Payments::save($v);
        Features::save(array_merge(Features::all(), [
            'coins' => (bool) ($v['coins'] ?? false),
            'buy_coins_web' => (bool) ($v['buy_coins_web'] ?? false),
            'buy_coins_app' => (bool) ($v['buy_coins_app'] ?? false),
            'paid_votes' => (bool) ($v['paid_votes'] ?? false),
            'withdrawals' => (bool) ($v['withdrawals'] ?? false),
        ]));
        $this->mount();
        Notification::make()->success()->title('Paiements enregistrés')->body('Le site et l\'app suivent tout de suite.')->send();
    }
}
