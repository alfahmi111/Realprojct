<?php

namespace App\Filament\Manager\Resources\PpmPlans;

use App\Filament\Support\Ui;
use App\Models\PpmPlan;
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

/** Generated screen: الصيانة الوقائية (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class PpmPlanResource extends Resource
{
    protected static ?string $model = PpmPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'الصيانة';

    protected static ?string $modelLabel = 'خطة الصيانة الوقائية';

    protected static ?string $pluralModelLabel = 'الصيانة الوقائية';

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
                TextInput::make('title_ar')->label('العمل')->required(),
                Select::make('asset_id')->label('الأصل')->options(fn () => \App\Models\Asset::whereNull('archived_at')->pluck('name_ar', 'id'))->searchable(),
                Select::make('space_id')->label('أو الموقع')->options(fn () => \App\Models\Space::whereNull('archived_at')->pluck('code', 'id'))->searchable(),
                TextInput::make('every_months')->label('كل (شهر)')->integer()->minValue(1)->required(),
                DatePicker::make('next_due_on')->label('الموعد القادم')->required(),
                Select::make('vendor_id')->label('المورّد')->options(fn () => \App\Models\Vendor::whereNull('archived_at')->pluck('name_ar', 'id')),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at'))
            ->columns([
                TextColumn::make('title_ar')->label('العمل'),
                TextColumn::make('every_months')->label('كل (شهر)'),
                TextColumn::make('next_due_on')->label('القادم')->date()->sortable(),
            ])
            ->recordActions([EditAction::make(), \App\Filament\Support\Ui::archive(),]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPpmPlans::route('/'),
            'create' => Pages\CreatePpmPlan::route('/create'),
            'edit' => Pages\EditPpmPlan::route('/{record}/edit'),
        ];
    }
}
