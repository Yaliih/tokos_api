<?php

namespace App\Filament\Resources\Withdrawals;

use App\Filament\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Models\CoinWithdrawal;
use App\Services\Coins\CoinService;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Retraits demandés : verser l'argent, puis marquer « versé », ou refuser (les coins reviennent). */
class WithdrawalResource extends Resource
{
    protected static ?string $model = CoinWithdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Coins';

    protected static ?string $modelLabel = 'retrait';

    protected static ?string $pluralModelLabel = 'retraits';

    protected static ?int $navigationSort = 1;

    public const STATUSES = ['pending' => 'À verser', 'paid' => 'Versé', 'rejected' => 'Refusé'];

    public static function shouldRegisterNavigation(): bool
    {
        return Features::on('coins');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = CoinWithdrawal::where('status', 'pending')->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.username')->label('Utilisateur')->formatStateUsing(fn ($state) => '@'.$state)->searchable(),
                TextColumn::make('coins')->label('Coins')->numeric(),
                TextColumn::make('amount')->label('À verser')->formatStateUsing(fn ($state, CoinWithdrawal $record) => number_format($state, 0, ',', ' ').' '.($record->currency === 'CDF' ? 'FC' : $record->currency))->weight('bold'),
                TextColumn::make('operator')->label('Vers')->formatStateUsing(fn (string $state): string => CoinWithdrawal::OPERATORS[$state] ?? $state)
                    ->description(fn (CoinWithdrawal $record): string => $record->phone),
                TextColumn::make('status')->label('État')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => ['pending' => 'warning', 'paid' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('provider_ref')->label('Référence')->placeholder('')->toggleable(),
                TextColumn::make('created_at')->label('Demandé')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label('État')->options(self::STATUSES)])
            ->recordActions([
                Action::make('paid')->label('Marquer versé')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (CoinWithdrawal $record): bool => $record->status === 'pending')
                    ->schema([TextInput::make('ref')->label('Référence de la transaction mobile money')->maxLength(100)])
                    ->action(fn (CoinWithdrawal $record, array $data) => app(CoinService::class)->markWithdrawalPaid($record, $data['ref'] ?? null)),
                Action::make('reject')->label('Refuser')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (CoinWithdrawal $record): bool => $record->status === 'pending')
                    ->schema([TextInput::make('note')->label('Raison')->required()->maxLength(150)])
                    ->action(fn (CoinWithdrawal $record, array $data) => app(CoinService::class)->rejectWithdrawal($record, $data['note'])),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListWithdrawals::route('/')];
    }
}
