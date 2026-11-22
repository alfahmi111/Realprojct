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

    private const KEYS = ['theme.preset', 'theme.custom_primary', 'date.documents', 'date.week_start', 'date.timezone', 'finance.fiscal_year_start_month', 'finance.vat_registered', 'finance.tax_period',
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
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('الهوية والسمة')->schema([
                Select::make('theme__preset')->label('السمة الجاهزة')->options(['clay' => 'طين نجدي', 'ink' => 'حبر ونحاس', 'plum' => 'برقوق']),
                TextInput::make('theme__custom_primary')->label('اللون الأساسي المخصص (#RRGGBB)')->regex('/^#[0-9A-Fa-f]{6}$/'),
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
            Section::make('التأجير والصيانة وقائمة الانتظار')->schema([
                TextInput::make('leasing__non_renewal_notice_days')->label('مهلة إشعار عدم التجديد (يوم)')->integer()->minValue(1),
                TextInput::make('leasing__renewal_survey_days')->label('موعد استطلاع التجديد (يوم قبل الانتهاء)')->integer()->minValue(1),
                Ui::money('maintenance__owner_approval_cap', 'سقف الصرف دون موافقة المالك'),
                TextInput::make('waitlist__confirm_interval_days')->label('دورية تأكيد الاهتمام (يوم)')->integer()->minValue(1),
            ])->columns(2),
            Section::make('الأمان والإشعارات')->schema([
                Toggle::make('security__dual_approval')->label('الموافقة المزدوجة مفعّلة'),
                TextInput::make('notifications__quiet_hours.start')->label('بداية ساعات الهدوء (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d$/'),
                TextInput::make('notifications__quiet_hours.end')->label('نهاية ساعات الهدوء (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d$/'),
            ])->columns(3),
        ]);
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
                if (in_array($k, ['finance.fiscal_year_start_month', 'leasing.non_renewal_notice_days', 'leasing.renewal_survey_days', 'waitlist.confirm_interval_days', 'maintenance.owner_approval_cap'], true) && $v !== null && $v !== '') {
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
        }, 'حُفظت الإعدادات');
    }
}
