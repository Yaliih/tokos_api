<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('Nouvelle annonce')->icon(Heroicon::OutlinedMegaphone)
                ->modalHeading('Nouvelle annonce')
                ->modalDescription('Elle arrive dans la cloche et en push (téléphone et navigateur) de ses destinataires.')
                ->schema(AnnouncementResource::fields())
                ->modalSubmitActionLabel('Valider')
                ->action(fn (array $data) => AnnouncementResource::store($data)),
        ];
    }
}
