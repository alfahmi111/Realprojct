<?php

namespace App\Filament\Support;

use App\Settings\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Each user's own preferences, in every role interface (spec §7.6, user level REQ-0326): dark mode (REQ-0338),
 * date display (REQ-0343), language and digits (REQ-0362, REQ-0363), accessibility (REQ-0373), daily digest (REQ-0358).
 * Keys locked by the subscriber admin are shown read-only (REQ-0321).
 */
class MyPreferencesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static ?string $navigationLabel = 'تفضيلاتي';

    protected static ?string $title = 'تفضيلاتي';

    protected static ?string $slug = 'my-preferences';

    protected static ?int $navigationSort = 100;

    public const KEYS = ['appearance.mode', 'date.display', 'locale.interface', 'numbers.digits', 'numbers.currency_display', 'a11y.font_size', 'a11y.high_contrast', 'a11y.reduce_motion', 'a11y.table_density', 'a11y.landing_page', 'notifications.daily_digest'];

    public ?array $data = [];

    public function mount(): void
    {
        $s = app(Settings::class);
        $v = [];
        foreach (self::KEYS as $k) {
            $v[str_replace('.', '__', $k)] = $s->get($k, auth()->id());
        }
        $this->form->fill($v);
    }

    private function locked(string $key): bool
    {
        return (bool) app(Settings::class)->resolve($key, auth()->id())['locked'];
    }

    public function form(Schema $schema): Schema
    {
        $l = fn ($k) => $this->locked($k);
        return $schema->statePath('data')->components([
            Section::make('العرض')->schema([
                Select::make('appearance__mode')->label('الوضع')->options(['auto' => 'تلقائي حسب الجهاز', 'light' => 'فاتح', 'dark' => 'داكن'])->disabled($l('appearance.mode')),
                Select::make('date__display')->label('عرض التاريخ')->options(['both_hijri_first' => 'كلاهما — الهجري أولاً', 'both_gregorian_first' => 'كلاهما — الميلادي أولاً', 'hijri' => 'هجري', 'gregorian' => 'ميلادي'])->disabled($l('date.display')),
                Select::make('locale__interface')->label('اللغة')->options(['ar' => 'العربية', 'en' => 'English'])->disabled($l('locale.interface')),
                Select::make('numbers__digits')->label('الأرقام')->options(['western' => '1 2 3', 'eastern' => '١ ٢ ٣'])->disabled($l('numbers.digits')),
                Select::make('numbers__currency_display')->label('عرض العملة')->options(['symbol' => '﷼', 'abbreviation' => 'ر.س'])->disabled($l('numbers.currency_display')),
            ])->columns(3),
            Section::make('إمكانية الوصول')->schema([
                Select::make('a11y__font_size')->label('حجم الخط')->options(['normal' => 'عادي', 'large' => 'كبير', 'larger' => 'أكبر']),
                Toggle::make('a11y__high_contrast')->label('تباين عالٍ'),
                Toggle::make('a11y__reduce_motion')->label('تقليل الحركة'),
                Select::make('a11y__table_density')->label('كثافة الجداول')->options(['comfortable' => 'مريحة', 'compact' => 'مضغوطة']),
                Select::make('a11y__landing_page')->label('الصفحة التي تفتح عند الدخول')->options(fn () => self::landingOptions()),
            ])->columns(3),
            Section::make('الإشعارات')->schema([
                Toggle::make('notifications__daily_digest')->label('ملخص يومي صباحي بدل الإشعارات المتفرقة')->disabled($l('notifications.daily_digest')),
            ]),
        ]);
    }

    /** Landing pages offered per role interface (relative to the panel). */
    public static function landingOptions(): array
    {
        return match (filament()->getCurrentPanel()?->getId()) {
            'manager' => ['' => 'الرئيسية', 'inbox' => 'صندوق المهام', 'kpis' => 'مؤشرات الأداء', 'leases' => 'العقود', 'work-orders' => 'أوامر العمل'],
            'owner' => ['' => 'الرئيسية', 'statement' => 'كشف الحساب', 'kpis' => 'مؤشرات الأداء'],
            'tenant' => ['' => 'الرئيسية', 'my-unit' => 'وحدتي'],
            'technician' => ['' => 'الرئيسية', 'tasks' => 'مهامي'],
            default => ['' => 'الرئيسية'],
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([Actions::make([Action::make('save')->label('حفظ')->submit('save')])])]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $s = app(Settings::class);
        Ui::attempt(function () use ($d, $s) {
            foreach (self::KEYS as $k) {
                if ($this->locked($k)) {
                    continue;
                }
                $v = $d[str_replace('.', '__', $k)] ?? null;
                $v = $v === '' ? null : $v;
                if (in_array($k, ['a11y.high_contrast', 'a11y.reduce_motion', 'notifications.daily_digest'], true)) {
                    $v = (bool) $v;
                }
                if ($s->get($k, auth()->id()) !== $v) {
                    $s->setUser(auth()->id(), $k, $v);
                }
            }
        }, 'حُفظت تفضيلاتك');
        $this->redirect(static::getUrl());
    }
}
