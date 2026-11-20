<?php

namespace App\Filament\Pages;

use App\Support\Registration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Ouvrir ou fermer les inscriptions : utile pendant la bêta pour éviter une affluence non maîtrisée.
 *
 * @property-read Schema $form
 */
class ManageRegistration extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Inscriptions';

    protected static ?string $title = 'Ouverture des inscriptions';

    protected static ?string $slug = 'inscriptions';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['max' => Registration::limit(), 'countries' => Registration::countries()]);
    }

    public function form(Schema $schema): Schema
    {
        $etat = Registration::count().' compte'.(Registration::count() > 1 ? 's' : '').' déjà créé'.(Registration::count() > 1 ? 's' : '');
        $limite = Registration::limit();
        $statut = $limite === 0 ? 'aucune limite' : ($limite.' comptes maximum'.(Registration::open() ? ' — encore ouverts' : ' — fermés'));

        return $schema
            ->components([
                Form::make([
                    Section::make('Nombre de comptes')
                        ->description($etat.' · '.$statut.'. Dès que le plafond est atteint, toute nouvelle inscription est refusée et aucun SMS n\'est envoyé.')
                        ->schema([
                            TextInput::make('max')
                                ->label('Comptes maximum (0 = illimité)')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->helperText('Pour la bêta : mets par exemple 50. Les comptes déjà créés comptent.'),
                        ]),
                    Section::make('Pays autorisés')
                        ->description('Laisse vide pour accepter tous les pays. Sinon, ajoute les indicatifs (ex. +243, +242) : les inscriptions venant d\'un autre pays seront refusées AVANT l\'envoi du SMS.')
                        ->schema([
                            TagsInput::make('countries')
                                ->label('Indicatifs autorisés')
                                ->placeholder('+243')
                                ->helperText('Un indicatif par étiquette. Vide = tous les pays.'),
                        ]),
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
        $state = $this->form->getRawState();
        $max = max(0, (int) ($state['max'] ?? 0));
        $countries = (array) ($state['countries'] ?? []);
        Registration::set($max);
        Registration::setCountries($countries);
        $this->form->fill(['max' => $max, 'countries' => Registration::countries()]);
        Notification::make()->success()
            ->title('Plafond enregistré')
            ->body($max === 0 ? 'Inscriptions ouvertes, sans limite.' : 'Limite : '.$max.' comptes.')
            ->send();
    }
}