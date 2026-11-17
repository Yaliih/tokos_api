<?php

namespace App\Filament\Pages;

use App\Support\NotifyRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Ce que Sosa notifie, type par type : dans la cloche (liste des notifications)
 * et en push (téléphone et navigateur, même Sosa fermé).
 *
 * @property-read Schema $form
 */
class ManageNotifications extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Ce qui est notifié';

    protected static ?string $slug = 'notifications-reglages';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    private static function key(string $type): string
    {
        return str_replace('.', '__', $type);
    }

    public function mount(): void
    {
        $state = [];
        foreach (NotifyRules::current() as $type => $v) {
            $state[self::key($type)] = $v;
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [Section::make('Comment ça marche')->schema([
            Text::make('« Cloche » : la notification apparaît dans la liste des notifications. « Push » : une alerte arrive aussi sur le téléphone ou dans le navigateur, même Sosa fermé. L\'interrupteur général des push est dans « Fonctions ».'),
        ])];
        foreach (NotifyRules::CATALOG as $group => $types) {
            $rows = [];
            foreach ($types as $type => [$label]) {
                $k = self::key($type);
                $rows[] = Grid::make(3)->schema([
                    Text::make($label)->columnSpan(1),
                    Toggle::make($k.'.app')->label('Cloche')->onColor('success'),
                    Toggle::make($k.'.push')->label('Push')->onColor('success'),
                ]);
            }
            $sections[] = Section::make($group)->schema($rows)->collapsible();
        }

        return $schema
            ->components([
                Form::make($sections)
                    ->livewireSubmitHandler('save')
                    ->footer([Actions::make([Action::make('save')->label('Enregistrer')->submit('save')->keyBindings(['mod+s'])])]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $values = [];
        foreach ($this->form->getState() as $k => $v) {
            $values[str_replace('__', '.', $k)] = (array) $v;
        }
        NotifyRules::save($values);
        $this->mount();
        Notification::make()->success()->title('Réglages des notifications enregistrés')->send();
    }
}
