<?php

namespace App\Filament\Pages;

use App\Services\Messaging\Messenger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
 * Ordre des canaux (WhatsApp, SMS) et test d'envoi. Les clés des fournisseurs restent dans .env.
 *
 * @property-read Schema $form
 */
class ManageMessaging extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'SMS et WhatsApp';

    protected static ?string $title = 'SMS et WhatsApp';

    protected static ?string $slug = 'messages-envoi';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public function mount(): void
    {
        $channels = app(Messenger::class)->channels();
        $this->form->fill(['first' => $channels[0] ?? 'log', 'second' => $channels[1] ?? null, 'third' => $channels[2] ?? null, 'test_phone' => '+243', 'test_text' => 'Test Sosa : si tu lis ce message, l\'envoi fonctionne.']);
    }

    private static function driverLine(string $channel): string
    {
        $drivers = array_values(array_filter(array_map('trim', explode(',', (string) config('messaging.drivers.'.$channel)))));
        $parts = array_map(fn ($d) => $d === 'log' ? 'journal (test)' : $d.(Messenger::providerReady($channel, $d) ? ' ✓' : ' (clés manquantes dans .env)'), $drivers);

        return Messenger::CHANNELS[$channel].' : '.(implode(', puis ', $parts) ?: 'journal (test)');
    }

    public function form(Schema $schema): Schema
    {
        $options = Messenger::CHANNELS;

        return $schema
            ->components([
                Form::make([
                    Section::make('Ordre d\'envoi')
                        ->description('Le message part par le premier canal. S\'il échoue, le suivant prend le relais.')
                        ->schema([
                            Select::make('first')->label('D\'abord')->options($options)->required()->native(false),
                            Select::make('second')->label('Si ça échoue')->options($options)->placeholder('Rien')->native(false),
                            Select::make('third')->label('En dernier recours')->options($options)->placeholder('Rien')->native(false),
                            Text::make(self::driverLine('whatsapp')),
                            Text::make(self::driverLine('sms')),
                        ])->columns(3),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([Actions::make([Action::make('save')->label('Enregistrer')->submit('save')])]),

                Section::make('Notifications push')
                    ->description('Alertes sur le téléphone, même application fermée. Chaque personne les active dans ses paramètres ou sur sa page Notifications.')
                    ->schema([
                        Text::make(fn (): string => \App\Models\PushSubscription::count().' appareil(s) abonné(s), dont '.\App\Models\PushSubscription::where('user_id', auth()->id())->count().' à toi.'),
                        Actions::make([
                            Action::make('pushTest')->label('M\'envoyer une notification test')->icon(Heroicon::OutlinedBell)->action('sendPushTest'),
                        ]),
                    ]),

                Section::make('Envoyer un test')
                    ->schema([
                        TextInput::make('test_phone')->label('Numéro (avec indicatif)')->tel(),
                        Textarea::make('test_text')->label('Message')->rows(2),
                        Actions::make([
                            Action::make('test')->label('Envoyer')->icon(Heroicon::OutlinedPaperAirplane)->action('sendTest'),
                        ]),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $channels = array_values(array_unique(array_filter([$state['first'] ?? null, $state['second'] ?? null, $state['third'] ?? null])));
        app(Messenger::class)->saveChannels($channels);
        Notification::make()->success()->title('Ordre enregistré')->body(implode(' puis ', array_map(fn ($c) => Messenger::CHANNELS[$c], $channels)))->send();
    }

    public function sendPushTest(): void
    {
        $n = app(\App\Services\Push\WebPush::class)->toUser(auth()->id(), ['title' => 'Sosa', 'body' => 'Les notifications push fonctionnent.', 'url' => url('/notifications')]);
        Notification::make()->{$n ? 'success' : 'warning'}()
            ->title($n ? 'Envoyée à '.$n.' appareil(s)' : 'Aucun appareil abonné')
            ->body($n ? null : 'Active d\'abord les notifications dans ton profil, sur le site.')->send();
    }

    public function sendTest(): void
    {
        $phone = (string) ($this->data['test_phone'] ?? '');
        if (strlen(preg_replace('/\D/', '', $phone)) < 9) {
            Notification::make()->danger()->title('Numéro incomplet')->send();

            return;
        }
        $ok = app(Messenger::class)->send($phone, (string) ($this->data['test_text'] ?? 'Test Sosa'), 'test');
        $last = \App\Models\OutboundMessage::latest('id')->first();
        Notification::make()->{$ok ? 'success' : 'danger'}()
            ->title($ok ? 'Envoyé par '.(Messenger::CHANNELS[$last->channel] ?? $last->channel) : 'Échec sur tous les canaux')
            ->body(collect($last->attempts)->map(fn ($a) => (Messenger::CHANNELS[$a['channel']] ?? $a['channel']).' : '.($a['ok'] ? 'ok' : ($a['error'] ?? 'échec')))->implode("\n"))
            ->send();
    }
}
