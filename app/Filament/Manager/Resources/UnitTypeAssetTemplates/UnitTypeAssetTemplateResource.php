<?php

namespace App\Filament\Manager\Resources\UnitTypeAssetTemplates;

use App\Filament\Support\Ui;
use App\Models\UnitTypeAssetTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Generated screen: الأصول الافتراضية لكل نوع (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class UnitTypeAssetTemplateResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('settings.subscriber');
    }
    protected static ?string $model = UnitTypeAssetTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?string $modelLabel = 'أصل افتراضي';

    protected static ?string $pluralModelLabel = 'الأصول الافتراضية لكل نوع';

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
                Select::make('unit_type')->label('نوع الوحدة')->options(collect(\App\Properties\UnitTypes::TYPES)->map(fn ($t) => $t['ar']))->required(),
                TextInput::make('name_ar')->label('الأصل')->required(),
                Select::make('category')->label('الفئة')->options(collect(\App\Maintenance\AssetTaxonomy::CATEGORIES)->map(fn ($c) => $c['ar']))->required(),
                TextInput::make('quantity')->label('العدد')->integer()->minValue(1)->default(1)->required(),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('لا أصول افتراضية بعد')->emptyStateDescription('حدّد ما تحويه كل شقة أو محل عادةً (مكيفات، سخان...) فيُضاف تلقائياً عند إنشاء المبنى بالمعالج.')
            ->columns([
                TextColumn::make('unit_type')->label('نوع الوحدة')->formatStateUsing(fn ($state) => \App\Properties\UnitTypes::TYPES[$state]['ar'] ?? $state),
                TextColumn::make('name_ar')->label('الأصل'),
                TextColumn::make('quantity')->label('العدد'),
            ])
            ->recordActions([EditAction::make(), ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnitTypeAssetTemplates::route('/'),
            'create' => Pages\CreateUnitTypeAssetTemplate::route('/create'),
            'edit' => Pages\EditUnitTypeAssetTemplate::route('/{record}/edit'),
        ];
    }
}
