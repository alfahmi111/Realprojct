<?php

namespace App\Filament\Manager\Pages;

use App\Filament\Manager\Resources\Users\UserResource;
use App\Filament\Support\ManagesTeam;
use App\Organization\TeamStructure;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The office's organisation chart (owner's request 2026-11-23): who reports to whom, by department, with drag-and-drop
 * moves. Same chart and rules as the platform admin sees (TeamStructure). Adding people stays in «المستخدمون».
 */
class TeamStructurePage extends Page
{
    use ManagesTeam;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?string $navigationLabel = 'الهيكل التنظيمي';

    protected static ?string $title = 'الهيكل التنظيمي';

    protected static ?string $slug = 'team';

    protected string $view = 'filament.manager.team';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('users.manage');
    }

    protected function teamSubscriberId(): int
    {
        return (int) auth()->user()->subscriber_id;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('addUser')->label('إضافة موظف')->icon(Heroicon::OutlinedUserPlus)->url(UserResource::getUrl('create'))];
    }

    public function editUserAction(): Action
    {
        return Action::make('editUser')->label('تعديل')->tooltip('تعديل البيانات والدور')->icon(Heroicon::OutlinedPencilSquare)->iconButton()->color('gray')
            ->url(fn (array $arguments) => UserResource::getUrl('edit', ['record' => $arguments['user']]));
    }

    protected function getViewData(): array
    {
        return ['team' => app(TeamStructure::class)->tree($this->teamSubscriberId())];
    }
}
