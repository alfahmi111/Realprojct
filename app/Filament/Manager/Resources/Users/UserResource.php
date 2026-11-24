<?php

namespace App\Filament\Manager\Resources\Users;

use App\Authorization\Role;
use App\Filament\Support\Ui;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\User;
use App\Models\UserScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** The subscriber's users: roles (REQ-0057…REQ-0063) and scope (REQ-0494). Unlimited users on every plan (REQ-0641). */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمون والصلاحيات';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('users.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('الاسم')->required(), TextInput::make('name_en')->label('الاسم بالإنجليزية'),
            TextInput::make('email')->label('البريد')->email()->required()->unique(ignoreRecord: true), TextInput::make('phone')->label('الجوال'),
            Select::make('role')->label('الدور')->options(collect(Role::cases())->reject(fn ($r) => $r === Role::PlatformAdmin)->mapWithKeys(fn ($r) => [$r->value => $r->labelAr()]))->required(),
            TextInput::make('password')->label('كلمة المرور')->password()->rule(\Illuminate\Validation\Rules\Password::default())->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state)),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at'))
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(), TextColumn::make('email')->label('البريد'),
                TextColumn::make('role')->label('الدور')->formatStateUsing(fn ($state) => $state instanceof Role ? $state->labelAr() : $state),
                TextColumn::make('scopes_count')->counts('scopes')->label('نطاقات'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('scope')->label('النطاق')
                    ->schema([
                        Select::make('scope_type')->label('النوع')->helperText('دون نطاق يرى الموظف كل عقارات المكتب حسب دوره؛ النطاق يحصره فيما تحدده.')->options(['portfolio' => 'محفظة', 'property' => 'عقار'])->required()->live(),
                        Select::make('scope_id')->label('العنصر')->options(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('scope_type') === 'portfolio' ? Portfolio::pluck('name_ar', 'id') : Property::whereNull('archived_at')->pluck('name_ar', 'id'))->required(),
                    ])
                    ->action(fn (User $record, array $data) => Ui::attempt(fn () => UserScope::firstOrCreate(['user_id' => $record->id, 'scope_type' => $data['scope_type'], 'scope_id' => (int) $data['scope_id']]), 'أُضيف النطاق')),
                Ui::archive('users.manage')->visible(fn (User $record) => $record->archived_at === null && $record->id !== auth()->id()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/'), 'create' => Pages\CreateUser::route('/create'), 'edit' => Pages\EditUser::route('/{record}/edit')];
    }
}
