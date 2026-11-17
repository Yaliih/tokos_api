<?php

namespace App\Filament\Pages;

use App\Support\Categories;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Les disciplines proposées partout : goûts à l'inscription, création d'un défi ou d'un concours, filtres, classements.
 *
 * @property-read Schema $form
 */
class ManageCategories extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Disciplines';

    protected static ?string $title = 'Disciplines';

    protected static ?string $slug = 'disciplines';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['items' => Categories::list()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([
                Section::make('Disciplines proposées')
                    ->description('Les disciplines proposées à la publication, à l\'inscription (goûts), dans les filtres et les classements, sur le site comme dans l\'app. Dans l\'ordre où elles s\'affichent : glisse pour changer l\'ordre. Désactive plutôt que supprimer : une discipline déjà utilisée garde son nom sur les anciennes publications.')
                    ->schema([
                        Repeater::make('items')->hiddenLabel()
                            ->schema([
                                Hidden::make('key'),
                                TextInput::make('label')->label('Nom')->required()->maxLength(40)->placeholder('Ex. Sciences'),
                                Toggle::make('active')->label('Proposée')->default(true)->inline(false),
                            ])
                            ->columns(2)->reorderable()->reorderableWithButtons()->collapsible(false)
                            ->addActionLabel('Ajouter une discipline')->defaultItems(0)
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null),
                    ]),
            ])
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('save')->label('Enregistrer')->submit('save'),
                    Action::make('reset')->label('Remettre la liste par défaut')->color('gray')->requiresConfirmation()
                        ->modalDescription('Les disciplines reviennent à la liste de départ de Sosa (16 disciplines). Tes ajouts et changements seront perdus.')->action('resetDefaults'),
                ])]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $r = Categories::save(array_values($this->form->getState()['items'] ?? []));
        $this->form->fill(['items' => Categories::list()]);
        Notification::make()->success()->title('Disciplines enregistrées')
            ->body($r['kept'] ? 'Gardées mais désactivées, car déjà utilisées : '.implode(', ', $r['kept']).'.' : null)->send();
    }

    public function resetDefaults(): void
    {
        Categories::reset();
        $this->form->fill(['items' => Categories::list()]);
        Notification::make()->success()->title('Liste par défaut remise')->send();
    }
}
