<?php

namespace App\Filament\Manager\Resources\LeasableUnits;

use App\Filament\Support\Ui;
use App\Models\LeasableUnit;
use App\Models\Space;
use App\Properties\BuildingWizard;
use App\Properties\UnitTypes;
use App\Support\Money\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Leasable units (REQ-0072): tracks + hospitality licence (REQ-0176), mandatory attributes per type (§4.3), price per
 * duration (REQ-0128), wizard step 4: merge, split, change type (REQ-0126).
 */
class LeasableUnitResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('properties.view');
    }

    /** Write gate (review M18): viewing roles do not edit. */
    public static function canEdit(Model $record): bool
    {
        return \App\Filament\Support\Ui::allowed('properties.edit');
    }
    protected static ?string $model = LeasableUnit::class;

    protected static ?string $recordTitleAttribute = 'code';   // global search in the header

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'العقارات';

    protected static ?string $modelLabel = 'وحدة تأجيرية';

    protected static ?string $pluralModelLabel = 'الوحدات التأجيرية';

    public static function canCreate(): bool
    {
        return false;   // created by the wizard
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('الاسم')->required(), TextInput::make('name_en')->label('الاسم بالإنجليزية'),
            Select::make('allowed_tracks')->label('المسارات المسموحة')->options(['long' => 'طويل فقط', 'short' => 'قصير فقط', 'both' => 'الاثنان'])->required()->live(),
            TextInput::make('hospitality_license_no')->label('رقم ترخيص الضيافة')->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('allowed_tracks') !== 'long'),
            DatePicker::make('hospitality_license_expires_on')->label('انتهاء الترخيص')->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('allowed_tracks') !== 'long'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at')->with('property'))
            ->columns([
                TextColumn::make('code')->label('الرمز')->searchable()->sortable(),
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('unit_type')->label('النوع')->formatStateUsing(fn ($state) => UnitTypes::get($state)['ar']),
                TextColumn::make('property.name_ar')->label('العقار'),
                IconColumn::make('is_composite')->label('مركّبة')->boolean(),
                Ui::moneyColumn('last_registered_rent_minor', 'آخر إيجار مسجل'),
            ])
            ->filters([SelectFilter::make('property_id')->label('العقار')->relationship('property', 'name_ar')])
            ->recordActions([
                EditAction::make(),
                Action::make('attributes')->authorize(fn () => \App\Filament\Support\Ui::allowed('properties.edit'))->label('الخصائص')->visible(fn (LeasableUnit $r) => ! $r->is_composite && UnitTypes::get($r->unit_type)['attributes'])
                    ->fillForm(fn (LeasableUnit $r) => $r->spaces->first()->attributes ?? [])
                    ->schema(fn (LeasableUnit $r) => self::attributeFields($r->unit_type))
                    ->action(fn (LeasableUnit $r, array $data) => Ui::attempt(fn () => app(BuildingWizard::class)->setAttributes($r->spaces->first(), self::castAttributes($r->unit_type, $data)), 'حُفظت الخصائص')),
                Action::make('price')->authorize(fn () => \App\Filament\Support\Ui::allowed('properties.edit'))->label('السعر')
                    ->schema([Select::make('duration_unit')->label('المدة')->options(['month' => 'شهري', 'year' => 'سنوي'])->required(), Ui::money('price', 'السعر')->required()])
                    ->action(fn (LeasableUnit $r, array $data) => Ui::attempt(fn () => app(BuildingWizard::class)->setPrice($r, $data['duration_unit'], Money::of((int) $data['price'])), 'حُفظ السعر')),
                Action::make('changeType')->authorize(fn () => \App\Filament\Support\Ui::allowed('properties.edit'))->label('تغيير النوع')->visible(fn (LeasableUnit $r) => ! $r->is_composite)
                    ->schema([Select::make('type')->label('النوع')->options(collect(UnitTypes::TYPES)->except(['whole_building', 'room'])->map(fn ($t) => $t['ar']))->required()])
                    ->action(fn (LeasableUnit $r, array $data) => Ui::attempt(fn () => app(BuildingWizard::class)->changeType($r->spaces->first(), $data['type']))),
                Action::make('merge')->authorize(fn () => \App\Filament\Support\Ui::allowed('properties.edit'))->label('دمج مع وحدة')->visible(fn (LeasableUnit $r) => ! $r->is_composite)
                    ->schema(fn (LeasableUnit $r) => [
                        Select::make('other')->label('الوحدة الأخرى')->options(LeasableUnit::where('property_id', $r->property_id)->where('id', '<>', $r->id)->where('is_composite', false)->whereNull('archived_at')->pluck('code', 'id'))->required(),
                        TextInput::make('code')->label('رمز الوحدة المدمجة')->required(), TextInput::make('name_ar')->label('الاسم')->required(),
                    ])
                    ->action(fn (LeasableUnit $r, array $data) => Ui::attempt(fn () => app(BuildingWizard::class)->merge($r->spaces->first(), LeasableUnit::find($data['other'])->spaces->first(), $data['code'], $data['name_ar']), 'تم الدمج')),
                Action::make('split')->authorize(fn () => \App\Filament\Support\Ui::allowed('properties.edit'))->label('تقسيم')->visible(fn (LeasableUnit $r) => ! $r->is_composite)
                    ->schema([\Filament\Forms\Components\Repeater::make('parts')->label('الأجزاء')->schema([TextInput::make('code')->label('الرمز')->required(), TextInput::make('name_ar')->label('الاسم')->required()])->minItems(2)->columns(2)])
                    ->action(fn (LeasableUnit $r, array $data) => Ui::attempt(fn () => app(BuildingWizard::class)->split($r->spaces->first(), $data['parts']), 'تم التقسيم')),
            ]);
    }

    /** Form fields from the unit type's mandatory attributes (§4.3). */
    public static function attributeFields(string $type): array
    {
        $labels = ['area_sqm' => 'المساحة (م²)', 'rooms' => 'الغرف', 'bathrooms' => 'الحمامات', 'furnished' => 'مفروشة', 'electricity_meter_no' => 'رقم عداد الكهرباء', 'water_account_no' => 'حساب المياه',
            'floors' => 'الأدوار', 'annex' => 'ملحق', 'pool' => 'مسبح', 'garden' => 'حديقة', 'frontage_m' => 'الواجهة (م)', 'permitted_activity' => 'النشاط المسموح', 'municipal_license_no' => 'رقم الرخصة البلدية',
            'furnished_equipped' => 'مفروش/مجهز', 'parking_spaces' => 'عدد المواقف', 'height_m' => 'الارتفاع (م)', 'floor_type' => 'نوع الأرضية', 'loading_door' => 'باب تحميل', 'three_phase_power' => 'كهرباء ثلاثية الطور',
            'location_description' => 'الموقع', 'number' => 'الرقم', 'covered' => 'مغطى', 'dedicated' => 'مخصص', 'dimensions' => 'الأبعاد', 'placement' => 'الموقع', 'illuminated' => 'مضاءة', 'municipal_permit_no' => 'رقم ترخيص البلدية',
            'occupied_area_sqm' => 'المساحة المشغولة (م²)', 'power_consumption' => 'استهلاك الكهرباء', 'lessee_entity' => 'الجهة المستأجرة', 'deed_no' => 'رقم الصك', 'permitted_use' => 'الاستخدام المسموح', 'use' => 'الاستخدام'];
        $fields = [];
        foreach (UnitTypes::get($type)['attributes'] as $name => $kind) {
            $label = $labels[$name] ?? $name;
            $fields[] = match (true) {
                $kind === 'bool' => Toggle::make($name)->label($label),
                $kind === 'int' => TextInput::make($name)->label($label)->integer()->minValue(0)->required(),
                in_array($kind, ['area', 'decimal'], true) => TextInput::make($name)->label($label)->regex('/^\d{1,9}(\.\d{1,2})?$/')->required(),
                str_starts_with($kind, 'enum:') => Select::make($name)->label($label)->options(array_combine(explode(',', substr($kind, 5)), explode(',', substr($kind, 5))))->required(),
                default => TextInput::make($name)->label($label)->required(),
            };
        }
        return $fields;
    }

    public static function castAttributes(string $type, array $data): array
    {
        foreach (UnitTypes::get($type)['attributes'] as $name => $kind) {
            if ($kind === 'int' && isset($data[$name])) {
                $data[$name] = (int) $data[$name];
            }
            if ($kind === 'bool') {
                $data[$name] = (bool) ($data[$name] ?? false);
            }
        }
        return $data;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLeasableUnits::route('/'), 'edit' => Pages\EditLeasableUnit::route('/{record}/edit')];
    }
}
