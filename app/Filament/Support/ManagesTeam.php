<?php

namespace App\Filament\Support;

use App\Organization\TeamStructure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/**
 * Moving people in the organisation chart, shared by the platform subscriber page and the office's own team page.
 * Drag-and-drop calls moveUser(); the "نقل" action is the keyboard way to do the same. Both go through TeamStructure.
 */
trait ManagesTeam
{
    abstract protected function teamSubscriberId(): int;

    /** Called by drag-and-drop in the chart. */
    public function moveUser(int $userId, ?int $managerId): void
    {
        Ui::attempt(fn () => app(TeamStructure::class)->move($this->teamSubscriberId(), $userId, $managerId), 'نُقل الموظف في الهيكل التنظيمي');
    }

    public function moveUserAction(): Action
    {
        return Action::make('moveUser')->label('نقل')->tooltip('نقل تحت مدير آخر')->icon(Heroicon::OutlinedArrowsRightLeft)->iconButton()->color('gray')
            ->modalHeading('نقل الموظف في الهيكل التنظيمي')
            ->schema(fn (array $arguments) => [
                Select::make('manager')->label('يتبع لـ')->required()
                    ->options(app(TeamStructure::class)->possibleManagers($this->teamSubscriberId(), (int) $arguments['user']))
                    ->helperText('تظهر فقط الأسماء التي يصح أن يتبع لها: صلاحية مساوية أو أعلى، ولا أحد ممن يتبعون له.'),
            ])
            ->action(fn (array $data, array $arguments) => $this->moveUser((int) $arguments['user'], (int) $data['manager']));
    }
}
