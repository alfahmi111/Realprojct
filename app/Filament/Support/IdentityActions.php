<?php

namespace App\Filament\Support;

use App\Identity\IdentityService;
use App\Models\IdentityVerification;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * «تحقّق» on tenants, owners and vendors (M15, Q30): Yaqeen for individuals, Wathq for companies, Nafath approval in
 * two steps. Each result is shown plainly; a mismatch shows the official name so the manager can correct the record.
 */
final class IdentityActions
{
    public static function group(): ActionGroup
    {
        $svc = fn () => app(IdentityService::class);
        $company = fn (Model $r) => ($r->kind ?? 'company') === 'company' || ! isset($r->national_id);
        $report = function (IdentityVerification $v) {
            $n = Notification::make()->title(IdentityService::SOURCE_AR[$v->source] . ': ' . IdentityService::STATUS_AR[$v->status]);
            if ($v->status === 'mismatch') {
                $n->body('الاسم في السجل الرسمي: ' . $v->returned_name . '. صحّح الاسم في الملف ثم أعد التحقق.')->warning()->persistent();
            } elseif ($v->status === 'verified') {
                $n->success();
            } else {
                $n->danger()->persistent();
            }
            $n->send();
        };
        $run = function (callable $fn) use ($report) {
            try {
                $report($fn());
            } catch (\Throwable $e) {
                Notification::make()->title('تعذّر التحقق')->body($e->getMessage())->danger()->persistent()->send();
            }
        };

        return ActionGroup::make([
            Action::make('yaqeen')->label('تحقّق عبر يقين')->icon(Heroicon::OutlinedIdentification)
                ->visible(fn (Model $r) => ! $company($r) && $svc()->available('yaqeen'))
                ->action(fn (Model $r) => $run(fn () => $svc()->yaqeen($r, auth()->user()))),
            Action::make('wathq')->label('تحقّق من السجل عبر واثق')->icon(Heroicon::OutlinedBuildingOffice2)
                ->visible(fn (Model $r) => $company($r) && $svc()->available('wathq'))
                ->action(fn (Model $r) => $run(fn () => $svc()->wathq($r, auth()->user()))),
            Action::make('nafath')->label('طلب موافقة عبر نفاذ')->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->visible(fn (Model $r) => ! $company($r) && $svc()->available('nafath'))
                ->requiresConfirmation()->modalDescription('يصل للشخص طلب في تطبيق نفاذ، ويختار فيه الرقم الذي سيظهر لك.')
                ->action(function (Model $r) use ($svc) {
                    try {
                        $s = $svc()->nafathStart($r, auth()->user());
                        Notification::make()->title('اطلب منه اختيار الرقم ' . $s['random'] . ' في تطبيق نفاذ')->body('ثم اضغط «تأكيد موافقة نفاذ» من القائمة نفسها.')->info()->persistent()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('تعذّر بدء نفاذ')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('nafathCheck')->label('تأكيد موافقة نفاذ')->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn (Model $r) => IdentityVerification::where('subject_type', IdentityService::type($r))->where('subject_id', $r->getKey())->where('source', 'nafath')->where('status', 'pending')->exists())
                ->action(function (Model $r) use ($svc, $run) {
                    $v = IdentityVerification::where('subject_type', IdentityService::type($r))->where('subject_id', $r->getKey())->where('source', 'nafath')->where('status', 'pending')->latest('id')->first();
                    $run(fn () => $svc()->nafathCheck($v, $r));
                }),
        ])->label('تحقّق')->icon(Heroicon::OutlinedShieldCheck)->button()->color('gray')
            ->visible(fn () => collect(['yaqeen', 'wathq', 'nafath'])->contains(fn ($s) => $svc()->available($s)));
    }

    /** «موثّق عبر يقين في 2026-11-23» under the name, or the open problem. */
    public static function badge(Model $r): ?string
    {
        $v = app(IdentityService::class)->latest($r);
        if (! $v) {
            return null;
        }
        return $v->status === 'verified'
            ? '✓ موثّق عبر ' . IdentityService::SOURCE_AR[$v->source] . ' ' . $v->verified_at?->format('Y-m-d')
            : '⚠ ' . IdentityService::SOURCE_AR[$v->source] . ': ' . IdentityService::STATUS_AR[$v->status];
    }
}
