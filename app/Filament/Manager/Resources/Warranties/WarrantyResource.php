<?php

namespace App\Filament\Manager\Resources\Warranties;

use App\Filament\Support\Ui;
use App\Models\Warranty;
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

/** Generated screen: الضمانات والعقود السنوية (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class WarrantyResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('maintenance.warranties');
    }
    protected static ?string $model = Warranty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'الصيانة';

    protected static ?string $modelLabel = 'الضمان / العقد';

    protected static ?string $pluralModelLabel = 'الضمانات والعقود السنوية';

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
                Select::make('kind')->label('النوع')->options(['manufacturer' => 'ضمان المصنّع', 'supplier' => 'ضمان المورّد', 'annual_contract' => 'عقد صيانة سنوي'])->required(),
                Select::make('asset_id')->label('الأصل')->options(fn () => \App\Models\Asset::whereNull('archived_at')->pluck('name_ar', 'id'))->searchable(),
                Select::make('building_id')->label('المبنى (للعقود السنوية)')->options(fn () => \App\Models\Building::pluck('name_ar', 'id')),
                Select::make('vendor_id')->label('المورّد الضامن')->options(fn () => \App\Models\Vendor::whereNull('archived_at')->pluck('name_ar', 'id'))->required(),
                TextInput::make('scope_ar')->label('النطاق')->required(),
                DatePicker::make('starts_on')->label('من')->required(),
                DatePicker::make('ends_on')->label('إلى')->required(),
                TextInput::make('reference')->label('المرجع'),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at'))
            ->emptyStateHeading('لا ضمانات ولا عقود صيانة بعد')->emptyStateDescription('سجّل ضمان المصنّع أو عقد الصيانة السنوي لكل جهاز، فيُوجَّه البلاغ عن الجهاز المضمون إلى جهة الضمان تلقائياً وتصلك تذكرة قبل انتهائه.')
            ->columns([
                TextColumn::make('scope_ar')->label('النطاق'),
                TextColumn::make('kind')->label('النوع')->badge()->color('gray')->formatStateUsing(fn ($state) => ['manufacturer' => 'ضمان المصنّع', 'supplier' => 'ضمان المورّد', 'annual_contract' => 'عقد صيانة سنوي'][$state] ?? $state),
                TextColumn::make('ends_on')->label('ينتهي')->date('Y-m-d')->sortable()->badge()->color(fn ($state) => $state < now() ? 'gray' : ($state < now()->addDays(60) ? 'warning' : 'success'))->description(fn ($record) => $record->ends_on < now() ? 'انتهى' : 'باقٍ ' . (int) now()->diffInDays($record->ends_on) . ' يوماً'),
            ])
            ->recordActions([EditAction::make(), \App\Filament\Support\Ui::archive('maintenance.edit'),]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarranties::route('/'),
            'create' => Pages\CreateWarranty::route('/create'),
            'edit' => Pages\EditWarranty::route('/{record}/edit'),
        ];
    }

    /** The user's property scope (REQ-0494, review M18 A2). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Filament\Support\PropertyScope::apply(parent::getEloquentQuery(), 'warranty');
    }
}
