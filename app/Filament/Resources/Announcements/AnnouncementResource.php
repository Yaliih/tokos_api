<?php

namespace App\Filament\Resources\Announcements;

use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Models\User;
use App\Services\Announcer;
use App\Support\Audiences;
use Filament\Schemas\Components\Text;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Annonces : notifier tous les inscrits ou une sélection (cloche + push),
 * maintenant, à une date, ou chaque mois (rappel). « M'envoyer un test » pour vérifier les push.
 */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Communauté';

    protected static ?string $modelLabel = 'annonce';

    protected static ?string $pluralModelLabel = 'annonces';

    protected static ?string $navigationLabel = 'Annonces';

    /** Le formulaire (création et modification). */
    public static function fields(): array
    {
        return [
            TextInput::make('title')->label('Titre')->required()->maxLength(80)->placeholder('Ex. Le tournoi des écoles commence !'),
            Textarea::make('body')->label('Message')->required()->maxLength(300)->rows(3),
            TextInput::make('url')->label('Lien ouvert au toucher (facultatif)')->maxLength(255)
                ->placeholder('/c/tournoi-des-ecoles ou /classements')->helperText('Une page de Sosa. Vide : l\'accueil.'),
            Radio::make('audience')->label('À qui')->required()->default('all')->live()
                ->options([
                    'all' => 'Tout le monde (inscrits, et téléphones ou navigateurs sans compte qui ont accepté les notifications)',
                    'segment' => 'Un groupe : nouveaux inscrits, plus actifs, moins actifs, venus une seule fois, une ville…',
                    'selected' => 'Des personnes choisies une à une',
                ]),
            Select::make('segment_key')->label('Groupe')->options(Audiences::options())->live()
                ->visible(fn (Get $get) => $get('audience') === 'segment')->required(fn (Get $get) => $get('audience') === 'segment'),
            TextInput::make('segment_n')->numeric()->integer()->minValue(1)->live(onBlur: true)
                ->label(fn (Get $get) => ucfirst(Audiences::SEGMENTS[$get('segment_key')][1] ?? 'Nombre'))
                ->placeholder(fn (Get $get) => (string) (Audiences::SEGMENTS[$get('segment_key')][2] ?? ''))
                ->visible(fn (Get $get) => $get('audience') === 'segment' && $get('segment_key') && $get('segment_key') !== 'city'),
            Select::make('segment_city')->label('Ville')->options(fn () => Audiences::cities())->searchable()->live()
                ->visible(fn (Get $get) => $get('audience') === 'segment' && $get('segment_key') === 'city')
                ->required(fn (Get $get) => $get('audience') === 'segment' && $get('segment_key') === 'city'),
            Text::make(fn (Get $get) => 'Aujourd\'hui, ce groupe compte '.Audiences::count((string) $get('segment_key'), (int) $get('segment_n'), $get('segment_city')).' personne(s). Il est recalculé à chaque envoi.')
                ->visible(fn (Get $get) => $get('audience') === 'segment' && $get('segment_key')),
            Select::make('user_ids')->label('Personnes')->multiple()->searchable()
                ->visible(fn (Get $get) => $get('audience') === 'selected')->required(fn (Get $get) => $get('audience') === 'selected')
                ->getSearchResultsUsing(fn (string $search) => User::query()
                    ->where(fn ($q) => $q->where('username', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")->orWhere('phone_number', 'like', "%{$search}%"))
                    ->limit(30)->get()->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name.' '.$u->last_name).' (@'.$u->username.')'])->all())
                ->getOptionLabelsUsing(fn (array $values) => User::whereIn('id', $values)->get()
                    ->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name.' '.$u->last_name).' (@'.$u->username.')'])->all()),
            Radio::make('when')->label('Quand')->required()->default('now')->live()
                ->options(['now' => 'Maintenant', 'later' => 'À une date', 'monthly' => 'Chaque mois (rappel)']),
            DateTimePicker::make('next_send_at')->label(fn (Get $get) => $get('when') === 'monthly' ? 'Premier envoi (puis le même jour chaque mois)' : 'Date et heure')
                ->seconds(false)->minDate(now()->subMinute())
                ->visible(fn (Get $get) => in_array($get('when'), ['later', 'monthly'], true))
                ->required(fn (Get $get) => in_array($get('when'), ['later', 'monthly'], true)),
        ];
    }

    /** Enregistre (et envoie tout de suite si « Maintenant »). */
    public static function store(array $data, ?Announcement $a = null): Announcement
    {
        $when = $data['when'] ?? 'now';
        $values = [
            'title' => $data['title'], 'body' => $data['body'], 'url' => $data['url'] ?: null,
            'audience' => $data['audience'] ?? 'all',
            'user_ids' => ($data['audience'] ?? 'all') === 'selected' ? array_map('intval', $data['user_ids'] ?? []) : null,
            'segment' => ($data['audience'] ?? 'all') === 'segment'
                ? ['key' => $data['segment_key'] ?? null, 'n' => (int) ($data['segment_n'] ?? 0), 'city' => $data['segment_city'] ?? null] : null,
            'repeat' => $when === 'monthly' ? 'monthly' : 'none',
            'next_send_at' => $when === 'now' ? null : $data['next_send_at'],
            'is_active' => true,
        ];
        if ($a) {
            $a->fill($values)->save();
        } else {
            $a = Announcement::create($values + ['created_by' => auth()->id()]);
        }
        if ($when === 'now') {
            self::sendNow($a);
        } else {
            Notification::make()->success()->title('Annonce programmée')->body('Premier envoi : '.$a->next_send_at?->format('d/m/Y H:i').'.')->send();
        }

        return $a;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Annonce')->description(fn (Announcement $r) => \Illuminate\Support\Str::limit($r->body, 70))->wrap()->searchable(),
                TextColumn::make('audience')->label('À qui')->formatStateUsing(fn (Announcement $r) => match ($r->audience) {
                    'selected' => count($r->user_ids ?? []).' personne(s)',
                    'segment' => (Audiences::SEGMENTS[$r->segment['key'] ?? ''][0] ?? 'Groupe').(($r->segment['key'] ?? '') === 'city' ? ' : '.($r->segment['city'] ?? '') : (! empty($r->segment['n']) ? ' ('.$r->segment['n'].')' : '')),
                    default => 'Tout le monde',
                }),
                TextColumn::make('repeat')->label('Fréquence')->badge()->formatStateUsing(fn (string $state) => $state === 'monthly' ? 'Chaque mois' : 'Une fois')
                    ->color(fn (string $state) => $state === 'monthly' ? 'info' : 'gray'),
                TextColumn::make('next_send_at')->label('Prochain envoi')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('last_sent_at')->label('Dernier envoi')->since()->placeholder('Jamais')
                    ->description(fn (Announcement $r) => $r->last_sent_at ? $r->last_recipients.' personne(s)' : null),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('send')->label('Envoyer maintenant')->icon(Heroicon::OutlinedPaperAirplane)->color('success')
                        ->requiresConfirmation()->modalDescription('L\'annonce part tout de suite à ses destinataires (cloche et push).')
                        ->action(function (Announcement $record) {
                            self::sendNow($record);
                        }),
                    Action::make('test')->label('M\'envoyer un test')->icon(Heroicon::OutlinedBeaker)
                        ->action(function (Announcement $record) {
                            // Cloche + push navigateur par le chemin normal ; push du téléphone avec un rapport détaillé
                            app(\App\Services\Social\NotificationService::class)->send([auth()->id()], 'announcement', $record->title, $record->body, $record->url ?: '/', null, ['_no_push' => true]);
                            $web = app(\App\Services\Push\WebPush::class)->toUser((int) auth()->id(), ['title' => $record->title, 'body' => $record->body, 'url' => url($record->url ?: '/'), 'tag' => 'test:'.$record->id]);
                            $lines = \App\Notifications\Channels\FcmChannel::diagnose((int) auth()->id(), $record->title, $record->body, $record->url ?: '/');
                            $lines[] = 'Navigateurs : '.$web.' envoi(s).';
                            Notification::make()->info()->title('Test envoyé')->body(implode("\n", $lines))->persistent()->send();
                        }),
                    Action::make('edit')->label('Modifier')->icon(Heroicon::OutlinedPencilSquare)
                        ->schema(self::fields())
                        ->fillForm(fn (Announcement $r) => $r->only(['title', 'body', 'url', 'audience', 'user_ids', 'next_send_at'])
                            + ['segment_key' => $r->segment['key'] ?? null, 'segment_n' => $r->segment['n'] ?? null, 'segment_city' => $r->segment['city'] ?? null]
                            + ['when' => $r->repeat === 'monthly' ? 'monthly' : ($r->next_send_at ? 'later' : 'now')])
                        ->action(fn (Announcement $record, array $data) => self::store($data, $record)),
                    Action::make('toggle')->label(fn (Announcement $r) => $r->is_active ? 'Mettre en pause' : 'Reprendre')
                        ->icon(fn (Announcement $r) => $r->is_active ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                        ->visible(fn (Announcement $r) => $r->repeat === 'monthly' || $r->next_send_at)
                        ->action(fn (Announcement $record) => $record->update(['is_active' => ! $record->is_active])),
                    DeleteAction::make()->label('Supprimer'),
                ]),
            ]);
    }

    public static function canCreate(): bool
    {
        return false; // création par le bouton « Nouvelle annonce » (fenêtre)
    }

    public static function getPages(): array
    {
        return ['index' => ListAnnouncements::route('/')];
    }

    /** Peu de destinataires : tout de suite. Beaucoup : dans la file d'attente (traitée chaque minute par le cron). */
    private static function sendNow(Announcement $a): void
    {
        if ($a->audience === 'selected' && count($a->user_ids ?? []) <= 100) {
            $n = app(Announcer::class)->send($a);
            Notification::make()->success()->title('Annonce envoyée')->body($n.' personne'.($n > 1 ? 's' : '').' notifiée'.($n > 1 ? 's' : '').'.')->send();

            return;
        }
        \App\Jobs\SendAnnouncement::dispatch($a->id);
        Notification::make()->success()->title('Envoi en cours')
            ->body('L\'annonce part dans la minute, par la file d\'attente. Le nombre de personnes touchées s\'affichera dans la liste.')->send();
    }
}
