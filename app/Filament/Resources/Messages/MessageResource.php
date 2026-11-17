<?php

namespace App\Filament\Resources\Messages;

use App\Filament\Resources\Messages\Pages\ListMessages;
use App\Models\OutboundMessage;
use App\Services\Messaging\Messenger;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Journal des SMS et WhatsApp : quel canal a marché, et pourquoi les autres ont échoué. */
class MessageResource extends Resource
{
    protected static ?string $model = OutboundMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $modelLabel = 'message envoyé';

    protected static ?string $pluralModelLabel = 'messages envoyés';

    protected static ?string $navigationLabel = 'Journal des envois';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('to')->label('Destinataire')->searchable(),
                TextColumn::make('purpose')->label('Type')->formatStateUsing(fn (?string $state): string => ['code' => 'Code', 'invitation' => 'Invitation', 'test' => 'Test'][$state] ?? (string) $state),
                TextColumn::make('body')->label('Message')->limit(50)->wrap(),
                TextColumn::make('status')->label('État')->badge()->formatStateUsing(fn (string $state): string => ['sent' => 'Envoyé', 'failed' => 'Échec', 'pending' => 'En cours'][$state] ?? $state)
                    ->color(fn (string $state): string => ['sent' => 'success', 'failed' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('channel')->label('Par')->formatStateUsing(fn (?string $state): string => Messenger::CHANNELS[$state] ?? (string) $state)->placeholder('Aucun'),
                TextColumn::make('attempts')->label('Essais')->wrap()
                    ->formatStateUsing(fn (OutboundMessage $record): string => collect($record->attempts ?? [])->map(fn ($attempt) => (Messenger::CHANNELS[$attempt['channel']] ?? $attempt['channel']).($attempt['ok'] ? ' : ok' : ' : '.($attempt['error'] ?? 'échec')))->implode(' / ')),
                TextColumn::make('created_at')->label('Date')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('État')->options(['sent' => 'Envoyé', 'failed' => 'Échec']),
                SelectFilter::make('channel')->label('Canal')->options(Messenger::CHANNELS),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListMessages::route('/')];
    }
}
