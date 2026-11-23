<?php

namespace App\Filament\Manager\Resources\Announcements\Pages;

use App\Filament\Manager\Resources\Announcements\AnnouncementResource;
use Filament\Resources\Pages\EditRecord;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
