<?php

namespace App\Filament\Resources\Competitions;

use App\Filament\Resources\Competitions\Pages\ListCompetitions;
use App\Models\Competition;
use App\Support\Participant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CompetitionResource extends Resource
{
    protected static ?string $model = Competition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Communauté';

    protected static ?string $modelLabel = 'compétition';

    protected static ?string $pluralModelLabel = 'compétitions';

    protected static ?int $navigationSort = 3;

    public const TYPES = ['challenge' => 'Défi', 'duel' => 'Duel', 'contest' => 'Concours', 'tournament' => 'Tournoi'];

    public const STATUSES = ['draft' => 'Brouillon', 'open' => 'Ouvert', 'live' => 'Votes en cours', 'completed' => 'Terminé', 'cancelled' => 'Annulé'];

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('organizer'))
            ->columns([
                TextColumn::make('title')->label('Titre')->searchable()->limit(50)
                    ->description(fn (Competition $record): string => Participant::name($record->organizer) ?? ''),
                TextColumn::make('type')->label('Type')->badge()->formatStateUsing(fn (string $state): string => self::TYPES[$state] ?? $state)
                    ->color(fn (string $state): string => ['challenge' => 'warning', 'duel' => 'danger', 'contest' => 'success', 'tournament' => 'primary'][$state] ?? 'gray'),
                TextColumn::make('status')->label('État')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => ['live' => 'success', 'open' => 'primary', 'cancelled' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('entries_count')->label('Participants')->numeric()->sortable(),
                TextColumn::make('votes_count')->label('Votes')->numeric()->sortable(),
                TextColumn::make('vote_cost')->label('Coins / vote')->numeric()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('hidden_at')->label('Masquée')->boolean()->getStateUsing(fn (Competition $record): bool => $record->hidden_at !== null)
                    ->trueColor('danger')->falseColor('gray')->trueIcon(Heroicon::OutlinedEyeSlash)->falseIcon(Heroicon::OutlinedEye),
                TextColumn::make('created_at')->label('Créée')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')->options(self::TYPES),
                SelectFilter::make('status')->label('État')->options(self::STATUSES),
                TernaryFilter::make('hidden')->label('Masquées')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('hidden_at'), false: fn (Builder $query) => $query->whereNull('hidden_at')),
            ])
            ->recordUrl(fn (Competition $record): string => $record->url(), shouldOpenInNewTab: true)
            ->recordActions([
                ActionGroup::make([
                    Action::make('hide')->label('Masquer')->icon(Heroicon::OutlinedEyeSlash)->color('danger')
                        ->visible(fn (Competition $record): bool => $record->hidden_at === null)
                        ->requiresConfirmation()->modalDescription('Elle disparaît du fil, de la recherche et sa page n\'est plus accessible, sauf pour l\'organisateur.')
                        ->action(fn (Competition $record) => $record->forceFill(['hidden_at' => now()])->save()),
                    Action::make('show')->label('Afficher à nouveau')->icon(Heroicon::OutlinedEye)->color('success')
                        ->visible(fn (Competition $record): bool => $record->hidden_at !== null)
                        ->action(fn (Competition $record) => $record->forceFill(['hidden_at' => null])->save()),
                    Action::make('cancel')->label('Annuler la compétition')->icon(Heroicon::OutlinedXCircle)->color('danger')
                        ->visible(fn (Competition $record): bool => in_array($record->status, ['open', 'live'], true))
                        ->schema([\Filament\Forms\Components\TextInput::make('reason')->label('Raison (envoyée aux participants)')->maxLength(200)])
                        ->modalDescription('Les votes s\'arrêtent, personne ne gagne. Les participants sont prévenus.')
                        ->action(fn (Competition $record, array $data) => app(\App\Services\Competition\CompetitionService::class)->cancel(auth()->user(), $record, $data['reason'] ?? null)),
                ]),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListCompetitions::route('/')];
    }
}
