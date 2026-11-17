<?php

namespace App\Filament\Resources\SuspectVotes;

use App\Filament\Resources\SuspectVotes\Pages\ListSuspectVotes;
use App\Models\Competition;
use App\Models\CompetitionVote;
use App\Services\Competition\VoteService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/** Votes marqués suspects par l'anti-triche : les garder, les annuler, ou suspendre le compte. */
class SuspectVoteResource extends Resource
{
    protected static ?string $model = CompetitionVote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Modération';

    protected static ?string $modelLabel = 'vote suspect';

    protected static ?string $pluralModelLabel = 'votes suspects';

    protected static ?string $navigationLabel = 'Votes suspects';

    protected static ?string $slug = 'votes-suspects';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('flagged', true);
    }

    public static function getNavigationBadge(): ?string
    {
        $n = CompetitionVote::where('flagged', true)->where('created_at', '>=', now()->subDays(7))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'entry', 'competition']))
            ->columns([
                TextColumn::make('competition.title')->label('Compétition')->limit(40)
                    ->url(fn (CompetitionVote $record): ?string => $record->competition?->url(), shouldOpenInNewTab: true),
                TextColumn::make('entry_id')->label('Pour')->formatStateUsing(fn (CompetitionVote $record): string => $record->entry?->displayName() ?? '?'),
                TextColumn::make('user.username')->label('Votant')->formatStateUsing(fn ($state) => '@'.$state)->searchable()
                    ->description(fn (CompetitionVote $record): string => 'inscrit '.($record->user?->created_at?->locale('fr')->diffForHumans() ?? '')),
                TextColumn::make('flag_reason')->label('Pourquoi')->badge()->color('warning')->wrap(),
                TextColumn::make('device_id')->label('Téléphone')->formatStateUsing(fn (?string $state): string => $state ? substr($state, 0, 6).'…' : '')
                    ->description(fn (CompetitionVote $record): string => $record->device_id
                        ? CompetitionVote::where('device_id', $record->device_id)->distinct()->count('user_id').' compte(s)' : '')
                    ->toggleable(),
                TextColumn::make('ip_address')->label('Réseau (IP)')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quantity')->label('Votes')->numeric(),
                TextColumn::make('created_at')->label('Quand')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('competition_id')->label('Compétition')->searchable()
                    ->options(fn () => Competition::whereIn('id', CompetitionVote::where('flagged', true)->distinct()->pluck('competition_id'))->pluck('title', 'id')->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('cancel')->label('Annuler le vote')->icon(Heroicon::OutlinedXCircle)->color('danger')
                        ->requiresConfirmation()->modalDescription('Le vote est retiré et les scores sont corrigés tout de suite.')
                        ->action(fn (CompetitionVote $record) => app(VoteService::class)->cancelVote($record)),
                    Action::make('keep')->label('C\'est un vrai vote')->icon(Heroicon::OutlinedCheck)->color('success')
                        ->action(fn (CompetitionVote $record) => $record->forceFill(['flagged' => false])->save()),
                    Action::make('suspend')->label('Suspendre le compte et annuler tous ses votes')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->requiresConfirmation()
                        ->action(function (CompetitionVote $record): void {
                            $user = $record->user;
                            if (! $user) {
                                return;
                            }
                            $user->forceFill(['is_active' => false])->save();
                            $user->tokens()->delete();
                            $n = 0;
                            CompetitionVote::where('user_id', $user->id)->get()->each(function ($vote) use (&$n) {
                                app(VoteService::class)->cancelVote($vote);
                                $n++;
                            });
                            Notification::make()->success()->title('Compte suspendu, '.$n.' vote(s) annulé(s)')->send();
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkAction::make('cancelAll')->label('Annuler ces votes')->icon(Heroicon::OutlinedXCircle)->color('danger')->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each(fn ($vote) => app(VoteService::class)->cancelVote($vote))),
                BulkAction::make('keepAll')->label('Ce sont de vrais votes')->icon(Heroicon::OutlinedCheck)
                    ->action(fn (Collection $records) => $records->each(fn ($vote) => $vote->forceFill(['flagged' => false])->save())),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSuspectVotes::route('/')];
    }
}
