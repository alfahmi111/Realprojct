<?php

namespace App\Filament\Manager\Resources\Properties;

use App\Filament\Support\Ui;
use App\Models\Property;
use App\Properties\BuildingWizard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Properties with their tree (spec §4.2). New properties are added through the wizard (§4.5). */
class PropertyResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('properties.view');
    }
    protected static ?string $model = Property::class;

    protected static ?string $recordTitleAttribute = 'name_ar';   // global search in the header

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'العقارات';

    protected static ?string $modelLabel = 'عقار';

    protected static ?string $pluralModelLabel = 'العقارات';

    public static function canCreate(): bool
    {
        return false;   // through the wizard
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('الاسم')->required(), TextInput::make('name_en')->label('الاسم بالإنجليزية'),
            TextInput::make('deed_number')->label('رقم الصك'), TextInput::make('ejar_property_number')->label('رقم العقار في إيجار'),
            TextInput::make('real_estate_registry_number')->label('رقم التسجيل العيني'), TextInput::make('region')->label('المنطقة'),
            TextInput::make('city')->label('المدينة'), Toggle::make('within_urban_boundary')->label('داخل النطاق العمراني'),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('العقار')->schema([
                TextEntry::make('name_ar')->label('الاسم'), TextEntry::make('deed_number')->label('الصك'), TextEntry::make('ejar_property_number')->label('رقم إيجار'),
                TextEntry::make('city')->label('المدينة'), TextEntry::make('portfolio.name_ar')->label('المحفظة'),
            ])->columns(3),
            Section::make('الشجرة')->schema([
                ViewEntry::make('tree')->view('filament.entries.property-tree')->state(fn (Property $record) => app(BuildingWizard::class)->tree($record)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at'))
            ->columns([
                TextColumn::make('name_ar')->label('العقار')->searchable(),
                TextColumn::make('city')->label('المدينة'),
                TextColumn::make('leasable_units_count')->counts('leasableUnits')->label('الوحدات التأجيرية'),
            ])
            ->headerActions([Action::make('wizard')->label('إضافة مبنى')->url(fn () => \App\Filament\Manager\Pages\AddBuildingWizard::getUrl())])
            ->recordActions([ViewAction::make(), EditAction::make(), Ui::archive()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProperties::route('/'),
            'view' => Pages\ViewProperty::route('/{record}'),
            'edit' => Pages\EditProperty::route('/{record}/edit'),
        ];
    }
}
