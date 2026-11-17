<?php

namespace App\Filament\Resources\Purchases;

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\CoinPurchase;
use App\Services\Coins\CoinService;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Achats de coins : suivre les paiements et valider à la main un paiement reçu hors du site. */
class PurchaseResource extends Resource
{
    protected static ?string $model = CoinPurchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Coins';

    protected static ?string $modelLabel = 'achat';

    protected static ?string $pluralModelLabel = 'achats de coins';

    protected static ?string $navigationLabel = 'Achats de coins';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Features::on('coins');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.username')->label('Utilisateur')->formatStateUsing(fn ($state) => '@'.$state)->searchable(),
                TextColumn::make('coins')->label('Coins')->numeric(),
                TextColumn::make('price')->label('Prix')->formatStateUsing(fn ($state, CoinPurchase $record) => number_format($state, 0, ',', ' ').' '.($record->currency === 'CDF' ? 'FC' : $record->currency)),
                TextColumn::make('phone')->label('Numéro')->placeholder(''),
                TextColumn::make('provider')->label('Paiement'),
                TextColumn::make('status')->label('État')->badge()->formatStateUsing(fn (string $state): string => ['pending' => 'En attente', 'paid' => 'Payé', 'failed' => 'Échoué'][$state] ?? $state)
                    ->color(fn (string $state): string => ['pending' => 'warning', 'paid' => 'success', 'failed' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('created_at')->label('Date')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label('État')->options(['pending' => 'En attente', 'paid' => 'Payé', 'failed' => 'Échoué'])])
            ->recordActions([
                Action::make('validate')->label('Valider le paiement')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (CoinPurchase $record): bool => $record->status === 'pending')
                    ->requiresConfirmation()->modalDescription('À faire seulement si l\'argent est bien arrivé. Les coins sont crédités tout de suite.')
                    ->action(fn (CoinPurchase $record) => app(CoinService::class)->completePurchase($record, 'admin:'.auth()->id())),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListPurchases::route('/')];
    }
}
