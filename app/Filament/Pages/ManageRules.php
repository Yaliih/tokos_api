<?php

namespace App\Filament\Pages;

use App\Support\Rules;
use BackedEnum;
use Filament\Actions\Action;
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
 * Règles qui évoluent : durées des vidéos et audios, poids des fichiers, participants, durées.
 * Le serveur, le site et l'app suivent tout de suite.
 *
 * @property-read Schema $form
 */
class ManageRules extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Règles et limites';

    protected static ?string $title = 'Règles et limites';

    protected static ?string $slug = 'regles';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    /** Les points des chemins de config ne vont pas dans un nom de champ. */
    private static function key(string $path): string
    {
        return str_replace('.', '__', $path);
    }

    public function mount(): void
    {
        $state = [];
        foreach (Rules::current() as $path => $v) {
            $state[self::key($path)] = $v;
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (Rules::fields() as $title => $fields) {
            $inputs = [];
            foreach ($fields as $path => [$label, $help, $min, $max]) {
                $inputs[] = $min === null
                    ? Toggle::make(self::key($path))->label($label)->onColor('success')
                    : TextInput::make(self::key($path))->label($label)->helperText($help)->numeric()->integer()->required()->minValue($min)->maxValue($max);
            }
            $sections[] = Section::make($title)->schema($inputs)->columns(2);
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
        $values = [];
        foreach ($this->form->getState() as $k => $v) {
            $values[str_replace('__', '.', $k)] = $v;
        }
        Rules::save($values);
        $this->mount();
        Notification::make()->success()->title('Règles enregistrées')->body('Le serveur, le site et l\'app suivent tout de suite.')->send();
    }
}
