<?php

namespace App\Filament\Manager\Resources\Assets;

use App\Filament\Support\Ui;
use App\Models\Asset;
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

/** Generated screen: الأصول (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'الصيانة';

    protected static ?string $modelLabel = 'الأصل';

    protected static ?string $pluralModelLabel = 'الأصول';

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
                Select::make('space_id')->label('الموقع في الشجرة')->options(fn () => \App\Models\Space::whereNull('archived_at')->orderBy('code')->get()->mapWithKeys(fn ($s) => [$s->id => $s->code . ' — ' . $s->name_ar]))->searchable()->required(),
                Select::make('category')->label('الفئة')->options(collect(\App\Maintenance\AssetTaxonomy::CATEGORIES)->map(fn ($c) => $c['ar']))->live()->required(),
                Select::make('type')->label('النوع')->options(fn (\Filament\Schemas\Components\Utilities\Get $get) => \App\Maintenance\AssetTaxonomy::CATEGORIES[$get('category')]['types'] ?? [])->required(),
                TextInput::make('qr_code')->label('رمز QR الداخلي')->required()->default(fn () => 'A-' . strtoupper(\Illuminate\Support\Str::random(10))),
                TextInput::make('name_ar')->label('الاسم')->required(),
                TextInput::make('brand')->label('الماركة'),
                TextInput::make('model')->label('الموديل'),
                TextInput::make('serial_number')->label('الرقم التسلسلي'),
                DatePicker::make('installed_on')->label('تاريخ التركيب'),
                DatePicker::make('warranty_ends_on')->label('انتهاء الضمان'),
                Select::make('condition')->label('الحالة (1–5)')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
                Ui::money('cost_minor', 'التكلفة'),
                TextInput::make('useful_life_months')->label('العمر الافتراضي (شهر)')->integer()->minValue(1),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at'))
            ->columns([
                TextColumn::make('qr_code')->label('الرمز')->searchable(),
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('category')->label('الفئة'),
                TextColumn::make('condition')->label('الحالة'),
                TextColumn::make('warranty_ends_on')->label('انتهاء الضمان')->date(),
            ])
            ->recordActions([EditAction::make(), \App\Filament\Support\Ui::archive(),]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'edit' => Pages\EditAsset::route('/{record}/edit'),
        ];
    }
}
