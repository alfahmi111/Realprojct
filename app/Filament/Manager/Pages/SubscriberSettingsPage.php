<?php

namespace App\Filament\Manager\Pages;

use App\Filament\Support\Ui;
use App\Models\Subscriber;
use App\Settings\Settings;
use App\Theming\Customization;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Subscriber settings (spec §7.6 أ–و): identity/theme by plan, documents date, week start, timezone, financial,
 * leasing, maintenance, waitlist, security, quiet hours. Every change is audited by the Settings service (REQ-0376).
 */
class SubscriberSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?string $navigationLabel = 'إعدادات المكتب';

    protected static ?string $title = 'إعدادات المكتب أو الشركة';

    protected static ?string $slug = 'subscriber-settings';

    public ?array $data = [];

    private const KEYS = ['theme.preset', 'theme.custom_primary', 'theme.logo_light', 'theme.logo_dark', 'date.documents', 'date.week_start', 'date.timezone', 'finance.fiscal_year_start_month', 'finance.vat_registered', 'finance.tax_period',
        'finance.fee.collection', 'finance.fee.percentage_basis', 'finance.fee.fixed_period', 'waitlist.long_threshold', 'security.session_minutes',
        'leasing.non_renewal_notice_days', 'leasing.renewal_survey_days', 'maintenance.owner_approval_cap', 'waitlist.confirm_interval_days', 'security.dual_approval', 'notifications.quiet_hours'];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('settings.subscriber');
    }

    public function mount(): void
    {
        $s = app(Settings::class);
        $values = [];
        foreach (self::KEYS as $k) {
            data_set($values, str_replace('.', '__', $k), $s->get($k));
        }
        foreach (app(\App\Privacy\DataRetention::class)->periods() as $type => $months) {
            $values['retention'][$type] = $months;
        }
        $sub = $this->subscriber();
        foreach (self::OFFICE_FIELDS as $f) {
            $v = $sub?->$f;
            $values["office__$f"] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        }
        $this->form->fill($values);
    }

    /** The office's own record — the same row the platform admin sees (one source of truth). */
    private const OFFICE_FIELDS = ['commercial_registration', 'vat_number', 'city', 'contact_name', 'contact_phone', 'contact_email', 'license_kind', 'license_no', 'license_expires_on'];

    private function subscriber(): ?Subscriber
    {
        return app(\App\Tenancy\TenantContext::class)->run(null, fn () => Subscriber::find(auth()->user()?->subscriber_id));
    }

    /** What the office sees of its subscription: plan, period, days left, state (Q24). */
    private function subscriptionSummary(): string
    {
        $s = $this->subscriber();
        if (! $s) {
            return '—';
        }
        $admin = app(\App\Plans\SubscriberAdmin::class);
        $today = \Carbon\CarbonImmutable::today();
        $plan = \App\Plans\PlanEntitlements::PLANS[$s->plan] ?? null;
        $parts = ['باقة ' . ($plan['ar'] ?? $s->plan) . ($plan && $plan['max_units'] ? ' (حتى ' . $plan['max_units'] . ' وحدة)' : ' (وحدات غير محدودة)')];
        $parts[] = 'الحالة: ' . \App\Plans\SubscriberAdmin::STATE_AR[$admin->state($s, $today)];
        if ($s->subscription_ends_on) {
            $d = $admin->daysRemaining($s, $today);
            $parts[] = 'ينتهي ' . $s->subscription_ends_on->format('Y-m-d') . ($d >= 0 ? " (متبقٍ $d يوماً)" : '');
        }
        return implode(' · ', $parts);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('المكتب والاشتراك')
                ->description(fn () => $this->subscriptionSummary() . '. التجديد وتغيير الباقة عبر إدارة المنصة.')
                ->schema([
                    TextInput::make('office__commercial_registration')->label('السجل التجاري')->maxLength(20),
                    // ZATCA BR-KSA-39: a VAT registration number is 15 digits starting and ending with 3. Needed on fee invoices.
                    TextInput::make('office__vat_number')->label('الرقم الضريبي للمكتب')->regex('/^3\d{13}3$/')
                        ->validationMessages(['regex' => 'الرقم الضريبي 15 رقماً يبدأ بـ 3 وينتهي بـ 3.'])
                        ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => (bool) $get('finance__vat_registered'))
                        ->helperText('مطلوب إذا كان المكتب مسجّلاً في ضريبة القيمة المضافة.'),
                    TextInput::make('office__city')->label('المدينة')->maxLength(60),
                    TextInput::make('office__contact_name')->label('مسؤول التواصل مع المنصة'),
                    TextInput::make('office__contact_phone')->label('جوال التواصل')->tel()->maxLength(30),
                    TextInput::make('office__contact_email')->label('بريد التواصل')->email(),
                ])->columns(3),
            Section::make('رخصة المكتب')
                ->description('رخصتك من الهيئة العامة للعقار. ينبّهك النظام قبل انتهائها بثلاثين يوماً ثم بسبعة أيام.')
                ->schema([
                    \Filament\Forms\Components\Select::make('office__license_kind')->label('نوع الرخصة')->options(\App\Compliance\ExpiryAlerts::LICENSE_KIND_AR),
                    TextInput::make('office__license_no')->label('رقم الرخصة')->maxLength(60),
                    \Filament\Forms\Components\DatePicker::make('office__license_expires_on')->label('تاريخ الانتهاء'),
                ])->columns(3),
            Section::make('التكاملات')
                ->description('حالة الربط بكل جهة خارجية. المزوّد الفعلي يُختار ويُربط بقرار من إدارة المنصة.')
                ->schema([\Filament\Schemas\Components\View::make('filament.manager.integrations-status')->viewData(fn () => ['rows' => \App\Integrations\IntegrationStatus::all()])]),
            Section::make('هوية المكتب')->description('شعار مكتبك ولونه يظهران لموظفيك وملاكك ومستأجريك في كل الواجهات، وفي صفحة المبنى العامة. الشعار مربع أو أفقي، PNG أو SVG بخلفية شفافة.')->schema([
                \Filament\Forms\Components\FileUpload::make('theme__logo_light')->label('الشعار')->image()->disk('local')->visibility('private')
                    ->directory(fn () => 'branding/' . (auth()->user()?->subscriber_id ?? 'x'))->maxSize(1024)->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/jpeg', 'image/webp'])
                    ->helperText('حتى 1 ميغابايت.'),
                \Filament\Forms\Components\FileUpload::make('theme__logo_dark')->label('الشعار للوضع الداكن (اختياري)')->image()->disk('local')->visibility('private')
                    ->directory(fn () => 'branding/' . (auth()->user()?->subscriber_id ?? 'x'))->maxSize(1024)->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/jpeg', 'image/webp'])
                    ->helperText('إن تُرك يظهر الشعار العادي على خلفية فاتحة صغيرة.'),
            ])->columns(2),
            Section::make('السمة والألوان')->schema([
                Select::make('theme__preset')->label('السمة الجاهزة')->options(['withaq' => 'وِثاق (الأساسية)', 'clay' => 'طين نجدي', 'ink' => 'حبر ونحاس', 'plum' => 'برقوق']),
                \Filament\Forms\Components\ColorPicker::make('theme__custom_primary')->label('لون المكتب (اختياري)')->hex()->regex('/^#[0-9A-Fa-f]{6}$/')
                    ->helperText('يحل محل لون السمة. يُرفض اللون الفاتح الذي لا يُقرأ عليه النص الأبيض.'),
            ])->columns(2),
            Section::make('التاريخ')->schema([
                Select::make('date__documents')->label('التاريخ في المستندات الرسمية')->options(['hijri' => 'هجري', 'gregorian' => 'ميلادي', 'both' => 'كلاهما']),
                Select::make('date__week_start')->label('بداية الأسبوع')->options(['sunday' => 'الأحد', 'saturday' => 'السبت', 'monday' => 'الاثنين']),
                Select::make('date__timezone')->label('المنطقة الزمنية')->options(['Asia/Riyadh' => 'الرياض (+3)', 'Asia/Dubai' => 'دبي (+4)', 'Asia/Kuwait' => 'الكويت (+3)', 'Asia/Bahrain' => 'البحرين (+3)', 'Asia/Qatar' => 'قطر (+3)', 'Asia/Muscat' => 'مسقط (+4)']),
            ])->columns(3),
            Section::make('المالية')->schema([
                TextInput::make('finance__fiscal_year_start_month')->label('شهر بداية السنة المالية')->integer()->minValue(1)->maxValue(12),
                Toggle::make('finance__vat_registered')->label('المكتب مسجّل في ضريبة القيمة المضافة'),
                Select::make('finance__tax_period')->label('الفترة الضريبية')->options(['monthly' => 'شهرية', 'quarterly' => 'ربع سنوية']),
            ])->columns(3),
            Section::make('أتعاب الإدارة')->description('تختلف طريقة احتساب الأتعاب وتحصيلها من مكتب إلى آخر، وتظهر في كشوف الملاك كما تضبطها هنا.')->schema([
                Select::make('finance__fee__collection')->label('طريقة تحصيل الأتعاب')->options(['deduct_from_remittance' => 'تُخصم من المبلغ المحوَّل للمالك', 'invoice_owner' => 'فاتورة منفصلة على المالك'])->required(),
                Select::make('finance__fee__percentage_basis')->label('النسبة تُحتسب من')->options(['collected' => 'الإيجار المحصَّل فعلاً', 'due' => 'الإيجار المستحق (المفوتر)'])->required(),
                Select::make('finance__fee__fixed_period')->label('المبلغ الثابت يُحتسب كل')->options(['monthly' => 'شهر', 'quarterly' => 'ثلاثة أشهر', 'semiannual' => 'ستة أشهر', 'annual' => 'سنة'])->required(),
            ])->columns(3),
            Section::make('التأجير والصيانة وقائمة الانتظار')->schema([
                TextInput::make('leasing__non_renewal_notice_days')->label('مهلة إشعار عدم التجديد (يوم)')->integer()->minValue(1),
                TextInput::make('leasing__renewal_survey_days')->label('موعد استطلاع التجديد (يوم قبل الانتهاء)')->integer()->minValue(1),
                Ui::money('maintenance__owner_approval_cap', 'سقف الصرف دون موافقة المالك'),
                TextInput::make('waitlist__confirm_interval_days')->label('دورية تأكيد الاهتمام (يوم)')->integer()->minValue(1),
                TextInput::make('waitlist__long_threshold')->label('تنبيه المالك إذا بلغت قائمة الانتظار (مهتمين) والوحدة شاغرة')->integer()->minValue(1),
            ])->columns(2),
            Section::make('الأمان والإشعارات')->schema([
                Toggle::make('security__dual_approval')->label('الموافقة المزدوجة مفعّلة'),
                TextInput::make('security__session_minutes')->label('إنهاء الجلسة بعد عدم نشاط (دقيقة)')->integer()->minValue(5)->maxValue(10080),
                TextInput::make('notifications__quiet_hours.start')->label('بداية ساعات الهدوء (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d$/'),
                TextInput::make('notifications__quiet_hours.end')->label('نهاية ساعات الهدوء (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d$/'),
            ])->columns(3),
            Section::make('مدد الاحتفاظ بالبيانات')
                ->description('مكتبك هو المتحكم في البيانات وفق نظام حماية البيانات الشخصية، فتحدد هنا مدة الاحتفاظ بكل نوع بالأشهر. الحقل الفارغ يعني الاحتفاظ دون حذف. السجلات المالية والعقود وسجل التدقيق لا تُحذف أبداً، وبيانات المهتمين تُحذف تلقائياً حين يتوقفون عن تأكيد اهتمامهم.')
                ->schema(collect(\App\Privacy\DataRetention::TYPES)->map(fn ($t, $type) => TextInput::make("retention.$type")->label($t[0] . ' (شهر)')->integer()->minValue(1)->maxValue(600)->helperText('يُحذف: ' . $t[1]))->values()->all())
                ->columns(2),
        ]);
    }

    /** Wathq check of the office's own commercial registration (REQ-0447 «والمشترك نفسه»). */
    protected function getHeaderActions(): array
    {
        $svc = fn () => app(\App\Identity\IdentityService::class);
        return [
            Action::make('wathqOffice')->label('تحقّق من سجل المكتب عبر واثق')->icon(\Filament\Support\Icons\Heroicon::OutlinedShieldCheck)->color('gray')
                ->visible(fn () => $svc()->available('wathq'))
                ->badge(fn () => ($v = $this->subscriber() ? $svc()->latest($this->subscriber()) : null) && $v->status === 'verified' ? '✓' : null)
                ->action(function () use ($svc) {
                    try {
                        $v = $svc()->wathq($this->subscriber(), auth()->user());
                        \Filament\Notifications\Notification::make()->title('واثق: ' . \App\Identity\IdentityService::STATUS_AR[$v->status])
                            ->body($v->status === 'mismatch' ? 'الاسم في السجل: ' . $v->returned_name : ($v->details['cr_expires_on'] ?? null ? 'ينتهي السجل ' . $v->details['cr_expires_on'] : null))
                            ->{$v->status === 'verified' ? 'success' : 'warning'}()->send();
                    } catch (\Throwable $e) {
                        \Filament\Notifications\Notification::make()->title('تعذّر التحقق')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([Actions::make([Action::make('save')->label('حفظ')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $settings = app(Settings::class);
        $subscriber = app(\App\Tenancy\TenantContext::class)->run(null, fn () => Subscriber::find(auth()->user()->subscriber_id));
        Ui::attempt(function () use ($d, $settings, $subscriber) {
            foreach (self::KEYS as $k) {
                $v = data_get($d, str_replace('.', '__', $k));
                if (in_array($k, ['finance.fiscal_year_start_month', 'leasing.non_renewal_notice_days', 'leasing.renewal_survey_days', 'waitlist.confirm_interval_days', 'maintenance.owner_approval_cap', 'waitlist.long_threshold', 'security.session_minutes'], true) && $v !== null && $v !== '') {
                    $v = (int) $v;
                }
                if ($v === '') {
                    $v = null;
                }
                if ($settings->get($k) === $v) {
                    continue;
                }
                match (true) {
                    str_starts_with($k, 'theme.') => app(Customization::class)->set($subscriber, $k, $v),
                    $k === 'security.dual_approval' => app(\App\Approvals\DualApproval::class)->setEnabled($subscriber, (bool) $v),
                    default => $settings->setSubscriber($k, $v),
                };
            }
            app(\App\Privacy\DataRetention::class)->save(array_map(fn ($v) => $v === '' ? null : $v, (array) ($d['retention'] ?? [])));
            $office = [];
            foreach (self::OFFICE_FIELDS as $f) {
                $v = $d["office__$f"] ?? null;
                $office[$f] = $v === '' ? null : $v;
            }
            $subscriber->fill($office);
            if ($subscriber->isDirty()) {
                app(\App\Tenancy\TenantContext::class)->run(null, fn () => $subscriber->save());   // audited (Auditable)
            }
        }, 'حُفظت الإعدادات');
    }
}
