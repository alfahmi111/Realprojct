<?php

namespace App\Filament\Platform\Pages;

use App\Filament\Support\Ui;
use App\Settings\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Platform settings (REQ-0323): default theme (decision 11), themes offered, readability contrast ratio for custom
 * colors (Q10), and the penetration-test launch gate (REQ-0508).
 */
class PlatformSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog8Tooth;

    protected static ?string $navigationLabel = 'إعدادات المنصة';

    protected static ?string $title = 'إعدادات المنصة';

    protected static ?string $slug = 'platform-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $s = app(Settings::class);
        $ratio = $s->get('theme.min_contrast_ratio');
        $this->form->fill([
            'default_theme' => $s->get('platform.default_theme'), 'available_themes' => $s->get('platform.available_themes'),
            'contrast' => $ratio ? $ratio['numerator'] . '/' . $ratio['denominator'] : null, 'pentest' => (bool) $s->get('launch.penetration_test_passed'),
            'grace_days' => $s->get('subscription.grace_days'), 'warning_days' => $s->get('subscription.warning_days'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $themes = ['withaq' => 'وِثاق (الأساسية)', 'clay' => 'طين نجدي', 'ink' => 'حبر ونحاس', 'plum' => 'برقوق'];
        return $schema->statePath('data')->components([
            Select::make('default_theme')->label('السمة الافتراضية للمنصة (القرار 11)')->options($themes),
            CheckboxList::make('available_themes')->label('السمات المتاحة للمشتركين')->options($themes),
            TextInput::make('contrast')->label('نسبة التباين المطلوبة للون المخصص (مثل 45/10)')->regex('/^\d+\/\d+$/'),
            Toggle::make('pentest')->label('اجتاز النظام فحص الاختراق'),
            TextInput::make('grace_days')->label('مهلة السماح بعد انتهاء الاشتراك (أيام)')->integer()->minValue(0)->maxValue(365)->required()
                ->helperText('بعدها يصبح المكتب للقراءة فقط تلقائياً، ويمكن تمديدها لكل مشترك من صفحته.'),
            TextInput::make('warning_days')->label('التنبيه بقرب الانتهاء قبل (أيام)')->integer()->minValue(1)->maxValue(365)->required(),
        ]);
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
            if ($d['default_theme']) {
                $s->setPlatform('platform.default_theme', $d['default_theme']);
            }
            $s->setPlatform('platform.available_themes', array_values($d['available_themes'] ?? []));
            if ($d['contrast']) {
                [$n, $den] = array_map('intval', explode('/', $d['contrast']));
                $s->setPlatform('theme.min_contrast_ratio', ['numerator' => $n, 'denominator' => $den]);
            }
            $s->setPlatform('launch.penetration_test_passed', (bool) $d['pentest']);
            foreach (['grace_days' => 'subscription.grace_days', 'warning_days' => 'subscription.warning_days'] as $field => $key) {
                if ((int) $d[$field] !== (int) $s->get($key)) {
                    $s->setPlatform($key, (int) $d[$field]);
                }
            }
        }, 'حُفظت إعدادات المنصة');
    }
}
