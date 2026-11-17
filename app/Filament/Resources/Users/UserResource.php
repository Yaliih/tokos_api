<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\CertificationService;
use App\Services\Coins\CoinService;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Communauté';

    protected static ?string $modelLabel = 'utilisateur';

    protected static ?string $pluralModelLabel = 'utilisateurs';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('visits')->withMax('visits', 'day'))
            ->columns([
                ImageColumn::make('avatar_url')->label('')->circular()->imageSize(36),
                TextColumn::make('first_name')->label('Nom')
                    ->formatStateUsing(fn (User $record): string => trim($record->first_name.' '.$record->last_name))
                    ->description(fn (User $record): string => '@'.$record->username)
                    ->searchable(['first_name', 'last_name', 'username']),
                TextColumn::make('phone_number')->label('Téléphone')
                    ->formatStateUsing(fn (User $record): string => $record->phone_code.' '.$record->phone_number)
                    ->searchable()->copyable(),
                IconColumn::make('verified_at')->label('Certifié')->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->verified_at !== null),
                IconColumn::make('is_admin')->label('Admin')->boolean(),
                IconColumn::make('is_active')->label('Actif')->boolean(),
                TextColumn::make('coins')->label('Coins')->numeric()->sortable()->visible(Features::on('coins')),
                TextColumn::make('created_at')->label('Inscrit')->since()->sortable()
                    ->tooltip(fn (User $record): string => $record->created_at?->locale('fr')->translatedFormat('j F Y à H\\hi') ?? ''),
                TextColumn::make('last_seen_at')->label('Dernière visite')->since()->sortable()->placeholder('Jamais')
                    ->getStateUsing(fn (User $record) => $record->last_seen_at ?? ($record->visits_max_day ? \Illuminate\Support\Carbon::parse($record->visits_max_day) : null))
                    ->color(fn ($state): string => ! $state ? 'danger' : (\Illuminate\Support\Carbon::parse($state)->lt(now()->subDays(30)) ? 'warning' : 'gray'))
                    ->tooltip(fn ($state): string => $state ? \Illuminate\Support\Carbon::parse($state)->locale('fr')->translatedFormat('j F Y à H\\hi') : ''),
                TextColumn::make('visits_count')->label('Jours de visite')->numeric()->sortable(),
                TextColumn::make('last_login_at')->label('Dernière connexion')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('verified')->label('Certifiés')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('verified_at'), false: fn (Builder $query) => $query->whereNull('verified_at')),
                TernaryFilter::make('is_active')->label('Actifs'),
                TernaryFilter::make('is_admin')->label('Admins'),
                \Filament\Tables\Filters\SelectFilter::make('recency')->label('Dernière visite')
                    ->options(['7' => 'Venus ces 7 jours', '30' => 'Venus ces 30 jours', 'gone30' => 'Pas revenus depuis 30 jours', 'never' => 'Jamais revenus'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        '7' => $query->where('last_seen_at', '>=', now()->subDays(7)),
                        '30' => $query->where('last_seen_at', '>=', now()->subDays(30)),
                        'gone30' => $query->where('last_seen_at', '<', now()->subDays(30)),
                        'never' => $query->has('visits', '<=', 1),
                        default => $query,
                    }),
            ])
            ->recordUrl(fn (User $record): string => '/@'.$record->username, shouldOpenInNewTab: true)
            ->recordActions([
                ActionGroup::make([
                    Action::make('certify')->label('Certifier')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                        ->visible(fn (User $record): bool => $record->verified_at === null)
                        ->requiresConfirmation()
                        ->action(fn (User $record) => $record->forceFill(['verified_at' => now()])->save()),
                    Action::make('uncertify')->label('Retirer la certification')->icon(Heroicon::OutlinedXCircle)->color('warning')
                        ->visible(fn (User $record): bool => $record->verified_at !== null)
                        ->requiresConfirmation()
                        ->action(fn (User $record) => app(CertificationService::class)->revoke($record)),
                    Action::make('suspend')->label('Suspendre le compte')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->visible(fn (User $record): bool => (bool) $record->is_active && $record->id !== auth()->id())
                        ->requiresConfirmation()->modalDescription('La personne ne peut plus se connecter. Ses contenus restent en place.')
                        ->action(function (User $record): void {
                            $record->forceFill(['is_active' => false])->save();
                            $record->tokens()->delete();
                        }),
                    Action::make('reactivate')->label('Réactiver le compte')->icon(Heroicon::OutlinedArrowPath)->color('success')
                        ->visible(fn (User $record): bool => ! $record->is_active)
                        ->action(fn (User $record) => $record->forceFill(['is_active' => true])->save()),
                    Action::make('admin')->label(fn (User $record): string => $record->is_admin ? 'Retirer l\'accès admin' : 'Donner l\'accès admin')
                        ->icon(Heroicon::OutlinedShieldCheck)
                        ->visible(fn (User $record): bool => $record->id !== auth()->id())
                        ->requiresConfirmation()
                        ->action(fn (User $record) => $record->forceFill(['is_admin' => ! $record->is_admin])->save()),
                    Action::make('coins')->label('Ajuster les coins')->icon(Heroicon::OutlinedCurrencyDollar)
                        ->visible(Features::on('coins'))
                        ->schema([
                            TextInput::make('amount')->label('Coins (négatif pour retirer)')->integer()->required()->notIn([0]),
                            TextInput::make('reason')->label('Raison')->required()->maxLength(120),
                        ])
                        ->action(function (User $record, array $data): void {
                            $coins = app(CoinService::class);
                            $amount = (int) $data['amount'];
                            $amount > 0
                                ? $coins->credit($record, $amount, 'adjustment', $data['reason'])
                                : $coins->debit($record, -$amount, 'adjustment', $data['reason']);
                            Notification::make()->success()->title('Solde : '.$coins->balance($record).' coins')->send();
                        }),
                ]),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
