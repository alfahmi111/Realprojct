<?php

namespace App\Filament\Platform\Resources\Subscribers;

use App\Models\Subscriber;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform admin: subscribers and plans (REQ-0065, REQ-0323). The platform never sees the subscribers' business data —
 * RLS keeps it outside any subscriber context (processor role, REQ-0453).
 */
class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'مشترك';

    protected static ?string $pluralModelLabel = 'المشتركون';

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label('الاسم بالعربية')->required(), TextInput::make('name_en')->label('الاسم بالإنجليزية')->required(),
            Select::make('plan')->label('الباقة')->options(['owner' => 'مالك', 'office' => 'مكتب', 'company' => 'شركة'])->required(),
            Select::make('status')->label('الحالة')->options(['active' => 'نشط', 'suspended' => 'موقوف'])->default('active')->required(),
            Section::make('مدير المشترك الأول')->schema([
                TextInput::make('manager_name')->label('الاسم')->required(), TextInput::make('manager_email')->label('البريد')->email()->required(),
                TextInput::make('manager_password')->label('كلمة المرور المؤقتة')->password()->rule(\Illuminate\Validation\Rules\Password::default())->required(),
            ])->columns(3)->visibleOn('create'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name_ar')->label('المشترك')->searchable(), TextColumn::make('plan')->label('الباقة')->badge(), TextColumn::make('status')->label('الحالة'), TextColumn::make('created_at')->label('أُنشئ')->date(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSubscribers::route('/'), 'create' => Pages\CreateSubscriber::route('/create'), 'edit' => Pages\EditSubscriber::route('/{record}/edit')];
    }
}
