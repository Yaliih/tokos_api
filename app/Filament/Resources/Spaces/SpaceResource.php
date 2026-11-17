<?php

namespace App\Filament\Resources\Spaces;

use App\Filament\Resources\Spaces\Pages\ListSpaces;
use App\Models\Space;
use App\Services\CertificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class SpaceResource extends Resource
{
    protected static ?string $model = Space::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Communauté';

    protected static ?string $modelLabel = 'Espace';

    protected static ?string $pluralModelLabel = 'Espaces';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('owner'))
            ->columns([
                ImageColumn::make('avatar_url')->label('')->circular()->imageSize(36),
                TextColumn::make('name')->label('Nom')->description(fn (Space $record): string => '#'.$record->slug)->searchable(['name', 'slug']),
                TextColumn::make('owner.username')->label('Créé par')->formatStateUsing(fn ($state) => '@'.$state),
                TextColumn::make('category')->label('Catégorie')
                    ->formatStateUsing(fn ($state, Space $record): string => \App\Support\Rankings::spaceLabel($record) ?? '')->badge(),
                TextColumn::make('members_count')->label('Membres'),
                IconColumn::make('is_verified')->label('Certifié')->boolean(),
                TextColumn::make('created_at')->label('Créé')->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_verified')->label('Certifiés'),
                \Filament\Tables\Filters\SelectFilter::make('category')->label('Catégorie')
                    ->options(collect(config('spaces.categories'))->map(fn ($c) => $c['label'])->all()),
            ])
            ->recordUrl(fn (Space $record): string => '/espaces/'.$record->slug, shouldOpenInNewTab: true)
            ->recordActions([
                Action::make('certify')->label(fn (Space $record): string => $record->is_verified ? 'Retirer la certification' : 'Certifier')
                    ->icon(Heroicon::OutlinedCheckBadge)->color(fn (Space $record): string => $record->is_verified ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (Space $record) => $record->is_verified ? app(CertificationService::class)->revoke($record) : $record->forceFill(['is_verified' => true])->save()),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSpaces::route('/')];
    }
}
