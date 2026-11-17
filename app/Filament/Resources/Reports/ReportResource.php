<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionMatch;
use App\Models\ContentReport;
use App\Models\PostComment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/** Signalements : voir le contenu, le masquer, ou classer sans suite. */
class ReportResource extends Resource
{
    protected static ?string $model = ContentReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Modération';

    protected static ?string $modelLabel = 'signalement';

    protected static ?string $pluralModelLabel = 'signalements';

    protected static ?int $navigationSort = 1;

    public const STATUSES = ['pending' => 'À traiter', 'resolved' => 'Contenu masqué', 'dismissed' => 'Sans suite'];

    public static function getNavigationBadge(): ?string
    {
        $count = ContentReport::where('status', 'pending')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    private static function target(ContentReport $report): array
    {
        $item = $report->reportable;

        return match (true) {
            $item instanceof Competition => ['Compétition', $item->title, $item->url()],
            $item instanceof CompetitionMatch => ['Duel', $item->competition?->title, $item->url()],
            $item instanceof CompetitionEntry => ['Participation', $item->competition?->title, $item->competition?->url()],
            $item instanceof PostComment => ['Commentaire', \Illuminate\Support\Str::limit((string) $item->content, 60), null],
            default => ['Supprimé', '', null],
        };
    }

    /** Masque le contenu signalé (et tous les signalements du même contenu passent à « masqué »). */
    public static function hideContent(ContentReport $report): void
    {
        $item = $report->reportable;
        match (true) {
            $item instanceof Competition => $item->forceFill(['hidden_at' => now()])->save(),
            $item instanceof CompetitionMatch => $item->competition?->forceFill(['hidden_at' => now()])->save(),
            $item instanceof CompetitionEntry => $item->forceFill(['status' => 'rejected'])->save(),
            $item instanceof PostComment => $item->delete(),
            default => null,
        };
        ContentReport::where('reportable_type', $report->reportable_type)->where('reportable_id', $report->reportable_id)
            ->where('status', 'pending')->update(['status' => 'resolved']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['reportable', 'user']))
            ->columns([
                TextColumn::make('reportable_type')->label('Contenu')
                    ->formatStateUsing(fn (ContentReport $record): string => self::target($record)[0])
                    ->description(fn (ContentReport $record): string => (string) self::target($record)[1])
                    ->url(fn (ContentReport $record): ?string => self::target($record)[2], shouldOpenInNewTab: true),
                TextColumn::make('reason')->label('Motif')->badge()->formatStateUsing(fn (string $state): string => ContentReport::REASONS[$state] ?? $state)
                    ->color(fn (string $state): string => in_array($state, ['shocking', 'harassment'], true) ? 'danger' : 'warning'),
                TextColumn::make('details')->label('Détails')->limit(60)->wrap()->placeholder('Aucun'),
                TextColumn::make('user.username')->label('Signalé par')->formatStateUsing(fn ($state) => '@'.$state),
                TextColumn::make('status')->label('État')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => ['pending' => 'danger', 'resolved' => 'success'][$state] ?? 'gray'),
                TextColumn::make('created_at')->label('Reçu')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('État')->options(self::STATUSES)->default('pending'),
                SelectFilter::make('reason')->label('Motif')->options(ContentReport::REASONS),
            ])
            ->recordActions([
                Action::make('hideContent')->label('Masquer le contenu')->icon(Heroicon::OutlinedEyeSlash)->color('danger')
                    ->visible(fn (ContentReport $record): bool => $record->status === 'pending' && $record->reportable !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Compétition ou duel : masqués du site. Participation : retirée. Commentaire : supprimé.')
                    ->action(fn (ContentReport $record) => self::hideContent($record)),
                Action::make('dismiss')->label('Sans suite')->icon(Heroicon::OutlinedCheck)->color('gray')
                    ->visible(fn (ContentReport $record): bool => $record->status === 'pending')
                    ->action(fn (ContentReport $record) => $record->update(['status' => 'dismissed'])),
            ])
            ->toolbarActions([
                BulkAction::make('dismissAll')->label('Classer sans suite')->icon(Heroicon::OutlinedCheck)
                    ->action(fn (Collection $records) => $records->each->update(['status' => 'dismissed'])),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListReports::route('/')];
    }
}
