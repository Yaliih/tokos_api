<?php

namespace App\Filament\Pages;

use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Cacher ou montrer les fonctions du site et de l'app.
 *
 * @property-read Schema $form
 */
class ManageFeatures extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Fonctions';

    protected static ?string $title = 'Fonctions visibles';

    protected static ?string $slug = 'fonctions';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(Features::all());
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (config('features.groups') as $group => $label) {
            $toggles = [];
            foreach (config('features.list') as $key => $feature) {
                if ($feature['group'] !== $group) {
                    continue;
                }
                $requires = $feature['requires'] ?? null;
                $toggles[] = Toggle::make($key)
                    ->label($feature['label'])
                    ->helperText($feature['help'].($requires ? ' Demande : '.config('features.list.'.$requires.'.label').'.' : ''))
                    ->onColor('success')
                    ->live()
                    ->disabled(fn (Get $get): bool => $requires !== null && ! $get($requires));
            }
            $sections[] = Section::make($label)->schema($toggles)->columns(2);
        }

        return $schema
            ->components([
                Form::make($sections)
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
        Features::save($this->form->getRawState());
        $this->form->fill(Features::all());
        Notification::make()->success()->title('Fonctions enregistrées')->body('Le site et l\'app suivent tout de suite.')->send();
    }
}
