<?php

namespace App\Filament\Manager\Pages;

use App\Filament\Support\Ui;
use App\Models\LeasableUnit;
use App\Models\Owner;
use App\Models\Portfolio;
use App\Properties\BuildingWizard;
use App\Properties\UnitTypes;
use App\Support\Money\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Add-building wizard (spec §4.5): 1 property data + owners/shares, 2 template, 3 quick generation (floors, units per
 * floor, numbering), 5 default assets, 6 pricing. Step 4 (merge / split / change type) is on the leasable-units screen;
 * step 0 (AI quick migration) is the "الانتقال السريع" screen.
 */
class AddBuildingWizard extends Page
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('properties.create');
    }
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'العقارات';

    protected static ?string $navigationLabel = 'إضافة مبنى (المعالج)';

    protected static ?string $title = 'معالج إضافة المبنى';

    protected static ?string $slug = 'add-building';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['numbering' => 'floor_seq', 'apply_default_assets' => true, 'owners' => [['numerator' => 1, 'denominator' => 1]]]);
    }

    public function form(Schema $schema): Schema
    {
        $types = collect(UnitTypes::TYPES)->except(['room', 'whole_building'])->map(fn ($t) => $t['ar'])->all();
        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make('بيانات العقار')->schema([
                    Select::make('portfolio_id')->label('المحفظة')->options(fn () => Portfolio::whereNull('archived_at')->orderBy('name_ar')->pluck('name_ar', 'id'))->placeholder('محفظة المالك (تُنشأ باسمه تلقائياً)')
                        ->createOptionForm([TextInput::make('name_ar')->label('اسم المحفظة')->required()])
                        ->createOptionUsing(fn (array $data) => Portfolio::create(['name_ar' => $data['name_ar']])->id)
                        ->helperText('المحفظة تجميع إداري لعقارات عميل واحد (مالك أو ورثة أو شركة). اتركها فارغة فتُنشأ «محفظة <اسم المالك>» تلقائياً، أو تُستعمل محفظته إن وُجدت. الحساب المالي (الأمانات والكشف) يبقى لكل مالك على حدة.'),
                    TextInput::make('name_ar')->label('اسم العقار')->required(),
                    \App\Filament\Support\Ui::propertyPhoto()->columnSpanFull(),
                    TextInput::make('name_en')->label('الاسم بالإنجليزية'),
                    TextInput::make('deed_number')->label('رقم الصك'),
                    TextInput::make('ejar_property_number')->label('رقم العقار في إيجار'),
                    TextInput::make('real_estate_registry_number')->label('رقم التسجيل العيني'),
                    TextInput::make('region')->label('المنطقة (للسياسات النظامية)'),
                    TextInput::make('city')->label('المدينة'),
                    Toggle::make('within_urban_boundary')->label('داخل النطاق العمراني'),
                    \App\Filament\Support\Ui::location(),
                    Repeater::make('owners')->label('الملاك وحصصهم (مجموع الحصص = 1)')->schema([
                        Select::make('owner_id')->label('المالك')->options(fn () => Owner::whereNull('archived_at')->pluck('name_ar', 'id'))->required(),
                        TextInput::make('numerator')->label('البسط')->integer()->minValue(1)->required(),
                        TextInput::make('denominator')->label('المقام')->integer()->minValue(1)->required(),
                    ])->columns(3)->minItems(1),
                ])->columns(2),
                Step::make('القالب')->schema([
                    Select::make('template')->label('القالب')->options(collect(BuildingWizard::TEMPLATES)->map(fn ($t) => $t['ar']))->required(),
                ]),
                Step::make('التوليد السريع')->schema([
                    TextInput::make('building_name')->label('اسم المبنى')->required(),
                    TextInput::make('code_prefix')->label('بادئة الترقيم (اختياري، للمجمعات)'),
                    Select::make('numbering')->label('نمط الترقيم')->options(['floor_seq' => '101، 102…', 'letter_seq' => 'A1، A2…'])->required(),
                    Repeater::make('floors')->label('الطوابق')->schema([
                        TextInput::make('number')->label('رقم الطابق (0 = الأرضي)')->integer()->minValue(0)->required(),
                        TextInput::make('name_ar')->label('اسم الطابق'),
                        Repeater::make('units')->label('الوحدات')->schema([
                            Select::make('type')->label('النوع')->options($types)->required(),
                            TextInput::make('count')->label('العدد')->integer()->minValue(1)->required(),
                        ])->columns(2)->minItems(1),
                    ])->collapsible(),
                    Repeater::make('common_areas')->label('المرافق المشتركة')->schema([TextInput::make('name_ar')->label('المرفق')->required()]),
                ]),
                Step::make('الأصول الافتراضية')->schema([
                    Toggle::make('apply_default_assets')->label('إضافة الأصول الافتراضية لكل نوع وحدة (من قائمة «الأصول الافتراضية لكل نوع»)'),
                ]),
                Step::make('التسعير')->schema([
                    Repeater::make('prices')->label('السعر السنوي لكل نوع وحدة (يمكن تعديله لكل وحدة لاحقاً)')->schema([
                        Select::make('type')->label('النوع')->options($types)->required(),
                        Ui::money('annual', 'السعر السنوي')->required(),
                    ])->columns(2),
                ]),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('create')
                ->footer([Actions::make([Action::make('create')->label('إنشاء العقار والمبنى')->submit('create')])]),
        ]);
    }

    public function create(): void
    {
        $d = $this->form->getState();
        $w = app(BuildingWizard::class);
        $property = Ui::attempt(fn () => DB::transaction(function () use ($w, $d) {
            $owners = collect($d['owners'])->map(fn ($o) => ['owner' => Owner::findOrFail($o['owner_id']), 'numerator' => (int) $o['numerator'], 'denominator' => (int) $o['denominator']])->all();
            $property = $w->createProperty(self::portfolioFor($d), collect($d)->only(['name_ar', 'name_en', 'deed_number', 'ejar_property_number', 'real_estate_registry_number', 'national_address', 'region', 'city', 'within_urban_boundary', 'latitude', 'longitude'])->all(), $owners, $d['template']);
            if (! empty($d['photo_path'])) {
                $property->forceFill(['photo_path' => $d['photo_path']])->save();
            }
            $floors = collect($d['floors'] ?? [])->map(fn ($f) => ['number' => (int) $f['number'], 'name_ar' => $f['name_ar'] ?: null, 'units' => collect($f['units'])->map(fn ($u) => ['type' => $u['type'], 'count' => (int) $u['count']])->all()])->all();
            $w->generate($property, ['name_ar' => $d['building_name'], 'numbering' => $d['numbering'], 'code_prefix' => $d['code_prefix'] ?? null, 'floors' => $floors, 'common_areas' => $d['common_areas'] ?? []]);
            if ($d['apply_default_assets'] ?? false) {
                $w->applyDefaultAssets($property);
            }
            foreach ($d['prices'] ?? [] as $p) {
                foreach (LeasableUnit::where('property_id', $property->id)->where('unit_type', $p['type'])->where('is_composite', false)->get() as $u) {
                    $w->setPrice($u, 'year', Money::of((int) $p['annual']));
                }
            }
            return $property;
        }), 'تم إنشاء العقار والمبنى');
        if ($property) {
            $this->redirect(\App\Filament\Manager\Resources\Properties\PropertyResource::getUrl('view', ['record' => $property]));
        }
    }

    /**
     * Portfolio per client (owner recommendation 2026-11-24, Q32): the chosen one, else the first owner's own portfolio,
     * created as «محفظة <اسم المالك>» on first use. The accounting entity stays the owner (trust ledger per owner).
     */
    public static function portfolioFor(array $d): Portfolio
    {
        if (! empty($d['portfolio_id'])) {
            return Portfolio::findOrFail($d['portfolio_id']);
        }
        $owner = Owner::findOrFail($d['owners'][0]['owner_id'] ?? 0);
        return Portfolio::whereNull('archived_at')->where('owner_id', $owner->id)->orderBy('id')->first()
            ?? Portfolio::create(['name_ar' => 'محفظة ' . $owner->name_ar, 'owner_id' => $owner->id]);
    }
}
