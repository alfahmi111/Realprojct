<?php

namespace App\Filament\Manager\Resources\OfferApprovalRules;

use App\Filament\Support\Ui;
use App\Models\OfferApprovalRule;
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

/** Generated screen: قواعد اعتماد العروض (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class OfferApprovalRuleResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('settings.subscriber');
    }
    protected static ?string $model = OfferApprovalRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?string $modelLabel = 'قاعدة اعتماد العروض';

    protected static ?string $pluralModelLabel = 'قواعد اعتماد العروض';

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
                TextInput::make('step')->label('الخطوة')->integer()->minValue(1)->required(),
                Select::make('approver_role')->label('دور المعتمد')->options(collect(\App\Authorization\Role::cases())->filter(fn ($r) => $r->authority() > 0)->mapWithKeys(fn ($r) => [$r->value => $r->labelAr()]))->required(),
                TextInput::make('min_discount_bp')->label('تطبق عند خصم ≥ (نقطة أساس، 100 = 1%)')->integer()->minValue(0)->default(0)->required(),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('لا قواعد اعتماد بعد')->emptyStateDescription('حدّد من يعتمد عرض التأجير إذا تجاوز الخصم نسبة معينة. دون قواعد تُعتمد العروض مباشرة.')
            ->columns([
                TextColumn::make('step')->label('الخطوة'),
                TextColumn::make('approver_role')->label('المعتمِد')->formatStateUsing(fn ($state) => \App\Authorization\Role::tryFrom($state)?->labelAr() ?? $state),
                TextColumn::make('min_discount_bp')->label('يُطلب عند خصم من')->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state / 100, 2), '0'), '.') . '%'),
            ])
            ->recordActions([EditAction::make(), ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOfferApprovalRules::route('/'),
            'create' => Pages\CreateOfferApprovalRule::route('/create'),
            'edit' => Pages\EditOfferApprovalRule::route('/{record}/edit'),
        ];
    }
}
