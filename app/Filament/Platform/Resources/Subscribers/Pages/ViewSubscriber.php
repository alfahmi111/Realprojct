<?php

namespace App\Filament\Platform\Resources\Subscribers\Pages;

use App\Filament\Platform\Resources\Subscribers\SubscriberResource;
use App\Filament\Support\Ui;
use App\Models\Subscriber;
use App\Plans\PlanEntitlements;
use App\Plans\SubscriberAdmin;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * One subscriber at a glance for the platform admin: office profile, subscription and days remaining, usage against
 * the plan, and the office's users with support actions. Every action goes through SubscriberAdmin (audited).
 */
class ViewSubscriber extends ViewRecord
{
    use \App\Filament\Support\ManagesTeam;

    protected static string $resource = SubscriberResource::class;

    protected function teamSubscriberId(): int
    {
        return $this->record->id;
    }

    protected string $view = 'filament.platform.subscriber-view';

    public function getTitle(): string
    {
        return $this->record->name_ar;
    }

    private function admin(): SubscriberAdmin
    {
        return app(SubscriberAdmin::class);
    }

    protected function getViewData(): array
    {
        /** @var Subscriber $s */
        $s = $this->record;
        $today = CarbonImmutable::today();
        return [
            's' => $s,
            'state' => $this->admin()->state($s, $today),
            'days' => $this->admin()->daysRemaining($s, $today),
            'graceDays' => $this->admin()->graceDays($s),
            'graceLeft' => $this->admin()->graceLeft($s, $today),
            'usage' => $this->admin()->usage($s),
            'team' => app(\App\Organization\TeamStructure::class)->tree($s->id),
            'plan' => PlanEntitlements::PLANS[$s->plan] ?? null,
            'addons' => $s->addons ?? [],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('renew')->label('تجديد الاشتراك')->icon(Heroicon::OutlinedArrowPath)->color('success')
                ->schema([Select::make('months')->label('مدة التجديد')->options([1 => 'شهر', 3 => '3 أشهر', 6 => '6 أشهر', 12 => 'سنة', 24 => 'سنتان'])->default(fn () => $this->record->billing_cycle === 'annual' ? 12 : 1)->required()])
                ->modalDescription('يُمدَّد من تاريخ النهاية الحالي، أو من اليوم إن كان الاشتراك قد انتهى.')
                ->action(fn (array $data) => Ui::attempt(fn () => $this->admin()->renew($this->record, (int) $data['months'], CarbonImmutable::today()), 'جُدِّد الاشتراك')),
            Action::make('extendGrace')->label('تمديد مهلة السماح')->icon(Heroicon::OutlinedClock)->color('warning')
                ->visible(fn () => in_array($this->admin()->state($this->record, CarbonImmutable::today()), ['grace', 'read_only', 'expiring'], true))
                ->schema([Select::make('days')->label('أيام إضافية')->options([3 => '3 أيام', 7 => 'أسبوع', 14 => 'أسبوعان', 30 => 'شهر'])->default(7)->required()])
                ->modalDescription(fn () => 'المهلة الحالية لهذا المشترك ' . $this->admin()->graceDays($this->record) . ' أيام. التمديد لهذا المشترك وحده، ويعود للافتراضي عند التجديد. إن كان المكتب للقراءة فقط وعاد داخل المهلة، يعود للعمل الكامل فوراً.')
                ->action(fn (array $data) => Ui::attempt(fn () => $this->admin()->extendGrace($this->record, (int) $data['days']), 'مُدِّدت مهلة السماح')),
            Action::make('changePlan')->label('تغيير الباقة')->icon(Heroicon::OutlinedSquare3Stack3d)->color('gray')
                ->schema([Select::make('plan')->label('الباقة الجديدة')->options(SubscriberResource::planOptions())->default(fn () => $this->record->plan)->required()])
                ->action(fn (array $data) => Ui::attempt(fn () => $this->admin()->changePlan($this->record, $data['plan']), 'غُيِّرت الباقة')),
            Action::make('suspend')->label('إيقاف الاشتراك')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn () => ! $this->record->isSuspended())
                ->schema([Textarea::make('reason')->label('سبب الإيقاف')->required()->minLength(5)->rows(2)])
                ->modalDescription('يتوقف دخول جميع مستخدمي المكتب فوراً حتى يُعاد التفعيل. البيانات لا تُمس.')
                ->action(fn (array $data) => Ui::attempt(fn () => $this->admin()->suspend($this->record, $data['reason']), 'أُوقف الاشتراك')),
            Action::make('activate')->label('إعادة التفعيل')->icon(Heroicon::OutlinedCheckCircle)->color('success')->requiresConfirmation()
                ->visible(fn () => $this->record->isSuspended())
                ->action(fn () => Ui::attempt(fn () => $this->admin()->activate($this->record), 'أُعيد تفعيل الاشتراك')),
            EditAction::make()->label('تعديل البيانات'),
        ];
    }

    /** Per-user support actions, rendered in the users table with the user id as an argument. */
    public function setPasswordAction(): Action
    {
        return Action::make('setPassword')->label('كلمة مرور مؤقتة')->tooltip('كلمة مرور مؤقتة')->icon(Heroicon::OutlinedKey)->iconButton()->color('gray')
            ->modalHeading('تعيين كلمة مرور مؤقتة')
            ->modalDescription('أبلغ المستخدم بها بطريقة آمنة. تُغلق جلساته المفتوحة، ويُسجَّل الإجراء في سجل المكتب.')
            ->schema([TextInput::make('password')->label('كلمة المرور')->password()->revealable()->required()->rule(\Illuminate\Validation\Rules\Password::default())])
            ->action(fn (array $data, array $arguments) => Ui::attempt(fn () => $this->admin()->setTemporaryPassword($this->record, (int) $arguments['user'], $data['password']), 'عُيِّنت كلمة المرور المؤقتة'));
    }

    public function resetTwoFactorAction(): Action
    {
        return Action::make('resetTwoFactor')->label('إعادة ضبط التحقق بخطوتين')->tooltip('إعادة ضبط التحقق بخطوتين')->icon(Heroicon::OutlinedShieldExclamation)->iconButton()->color('warning')
            ->requiresConfirmation()->modalDescription('لمن فقد جهاز التحقق: يطلب منه النظام إعداد التحقق من جديد عند دخوله التالي.')
            ->action(fn (array $arguments) => Ui::attempt(fn () => $this->admin()->resetTwoFactor($this->record, (int) $arguments['user']), 'أُعيد ضبط التحقق بخطوتين'));
    }

    public function toggleUserAction(): Action
    {
        $label = fn (array $arguments) => ($arguments['active'] ?? true) ? 'إيقاف المستخدم' : 'تفعيل المستخدم';
        return Action::make('toggleUser')->label($label)->tooltip($label)->iconButton()
            ->icon(fn (array $arguments) => ($arguments['active'] ?? true) ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
            ->color(fn (array $arguments) => ($arguments['active'] ?? true) ? 'danger' : 'success')->requiresConfirmation()
            ->action(fn (array $arguments) => Ui::attempt(fn () => $this->admin()->setUserActive($this->record, (int) $arguments['user'], ! ($arguments['active'] ?? true)), 'حُدِّث المستخدم'));
    }
}
