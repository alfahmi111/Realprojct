<?php

namespace App\Filament\Manager\Pages;

use App\Dashboards\ManagerInbox;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** One task inbox for the phone (REQ-0315): pending approvals, new reports, escalated arrears. */
class InboxPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'صندوق المهام';

    protected static ?string $title = 'صندوق المهام';

    protected static ?string $slug = 'inbox';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.inbox';

    protected function getViewData(): array
    {
        return ['inbox' => app(ManagerInbox::class)->for(auth()->user())];
    }
}
