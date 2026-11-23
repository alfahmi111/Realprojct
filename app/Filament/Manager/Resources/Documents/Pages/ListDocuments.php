<?php

namespace App\Filament\Manager\Resources\Documents\Pages;

use App\Filament\Manager\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    public function getSubheading(): ?string
    {
        return 'صكوك العقارات ورخصها وشهاداتها النظامية ومستندات الأطراف، بتواريخ انتهائها ونسخها السابقة ومن اطّلع عليها.';
    }
}
