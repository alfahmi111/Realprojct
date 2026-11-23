<?php

namespace App\Filament\Manager\Resources\Announcements\Pages;

use App\Filament\Manager\Resources\Announcements\AnnouncementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
