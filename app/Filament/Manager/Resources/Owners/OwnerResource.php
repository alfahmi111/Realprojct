<?php

namespace App\Filament\Manager\Resources\Owners;

use App\Filament\Support\Ui;
use App\Models\Owner;
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

/** Generated screen: الملاك (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class OwnerResource extends Resource
{
    protected static ?string $model = Owner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'العقارات';

    protected static ?string $modelLabel = 'المالك';

    protected static ?string $pluralModelLabel = 'الملاك';

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
                Select::make('kind')->label('النوع')->options(['individual' => 'فرد', 'heirs' => 'ورثة', 'company' => 'شركة'])->required(),
                TextInput::make('name_ar')->label('الاسم بالعربية')->required(),
                TextInput::make('name_en')->label('الاسم بالإنجليزية'),
                TextInput::make('national_id')->label('رقم الهوية')->password()->revealable(),
                TextInput::make('commercial_registration')->label('السجل التجاري'),
                Toggle::make('vat_registered')->label('مسجّل في ضريبة القيمة المضافة'),
                TextInput::make('vat_number')->label('الرقم الضريبي')->maxLength(20),
                Toggle::make('resident')->label('مقيم')->default(true),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at'))
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('kind')->label('النوع'),
                IconColumn::make('vat_registered')->label('مسجّل ضريبياً')->boolean(),
            ])
            ->recordActions([EditAction::make(), \App\Filament\Support\Ui::archive(),]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOwners::route('/'),
            'create' => Pages\CreateOwner::route('/create'),
            'edit' => Pages\EditOwner::route('/{record}/edit'),
        ];
    }
}
