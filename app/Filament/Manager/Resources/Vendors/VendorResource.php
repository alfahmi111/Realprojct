<?php

namespace App\Filament\Manager\Resources\Vendors;

use App\Filament\Support\Ui;
use App\Models\Vendor;
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

/** Generated screen: الموردون (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class VendorResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('maintenance.vendors');
    }
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'الصيانة';

    protected static ?string $modelLabel = 'المورد';

    protected static ?string $pluralModelLabel = 'الموردون';

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
                TextInput::make('name_ar')->label('الاسم')->required(),
                TextInput::make('name_en')->label('الاسم بالإنجليزية'),
                TextInput::make('commercial_registration')->label('السجل التجاري'),
                TextInput::make('phone')->label('الجوال'),
                TextInput::make('email')->label('البريد')->email(),
                Select::make('rating')->label('التقييم')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at'))
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('commercial_registration')->label('السجل'),
                TextColumn::make('rating')->label('التقييم'),
            ])
            ->recordActions([EditAction::make(), \App\Filament\Support\Ui::archive(),]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVendors::route('/'),
            'create' => Pages\CreateVendor::route('/create'),
            'edit' => Pages\EditVendor::route('/{record}/edit'),
        ];
    }
}
