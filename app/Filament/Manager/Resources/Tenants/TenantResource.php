<?php

namespace App\Filament\Manager\Resources\Tenants;

use App\Filament\Support\Ui;
use App\Models\Tenant;
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

/** Generated screen: المستأجرون (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class TenantResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('leasing.view');
    }
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $modelLabel = 'المستأجر';

    protected static ?string $pluralModelLabel = 'المستأجرون';

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
                Select::make('kind')->label('النوع')->options(['individual' => 'فرد', 'company' => 'منشأة'])->required(),
                TextInput::make('name_ar')->label('الاسم بالعربية')->required(),
                TextInput::make('name_en')->label('الاسم بالإنجليزية'),
                TextInput::make('national_id')->label('رقم الهوية')->password()->revealable(),
                TextInput::make('commercial_registration')->label('السجل التجاري'),
                TextInput::make('vat_number')->label('الرقم الضريبي (للمنشآت)')->maxLength(20),
                TextInput::make('phone')->label('الجوال')->tel(),
                TextInput::make('email')->label('البريد')->email(),
                Select::make('messages_locale')->label('لغة الرسائل')->options(['ar' => 'العربية', 'en' => 'English']),
                Section::make('العنوان الوطني')->schema([TextInput::make('national_address.building_no')->label('رقم المبنى')->regex('/^\\d{4}$/'), TextInput::make('national_address.street')->label('الشارع'), TextInput::make('national_address.district')->label('الحي'), TextInput::make('national_address.city')->label('المدينة'), TextInput::make('national_address.postal_code')->label('الرمز البريدي')->regex('/^\\d{5}$/'), TextInput::make('national_address.additional_no')->label('الرقم الإضافي')->regex('/^\\d{4}$/')])->columns(3),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at'))
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('kind')->label('النوع'),
                TextColumn::make('phone')->label('الجوال'),
            ])
            ->recordActions([EditAction::make(), \App\Filament\Support\Ui::archive(),]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
