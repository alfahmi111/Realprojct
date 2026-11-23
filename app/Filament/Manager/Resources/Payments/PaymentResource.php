<?php

namespace App\Filament\Manager\Resources\Payments;

use App\Filament\Support\Ui;
use App\Finance\PaymentService;
use App\Models\Payment;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Record a payment and issue a receipt (REQ-0316); money goes to the manager or owner, never the platform (REQ-0436). */
class PaymentResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('finance.view');
    }
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $modelLabel = 'سند قبض';

    protected static ?string $pluralModelLabel = 'الدفعات وسندات القبض';

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('tenant_id')->label('المستأجر')->options(fn () => Tenant::whereNull('archived_at')->pluck('name_ar', 'id'))->searchable()->required(),
            Ui::money('amount_minor', 'المبلغ')->required(),
            Select::make('method')->label('الطريقة')->options(['mada' => 'مدى', 'apple_pay' => 'Apple Pay', 'card' => 'بطاقة', 'sadad' => 'سداد', 'bank_transfer' => 'تحويل بنكي', 'cash' => 'نقداً'])->required(),
            DatePicker::make('received_on')->label('تاريخ الاستلام')->required()->default(now()),
            Select::make('paid_to')->label('استُلم في حساب')->options(['manager' => 'المدير (حساب أمانات الملاك)', 'owner' => 'المالك مباشرة'])->required(),
            TextInput::make('reference')->label('المرجع'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->latest('received_on'))
            ->columns([
                TextColumn::make('receipt_number')->label('رقم السند')->searchable(), TextColumn::make('received_on')->label('التاريخ')->date()->sortable(),
                TextColumn::make('tenant.name_ar')->label('المستأجر')->searchable()->placeholder('—'),
                Ui::moneyColumn('amount_minor', 'المبلغ'),
                TextColumn::make('method')->label('الطريقة')->formatStateUsing(fn ($state) => ['mada' => 'مدى', 'apple_pay' => 'Apple Pay', 'card' => 'بطاقة', 'sadad' => 'سداد', 'bank_transfer' => 'تحويل بنكي', 'cash' => 'نقداً'][$state] ?? $state),
                TextColumn::make('paid_to')->label('في حساب')->formatStateUsing(fn ($state) => ['manager' => 'المكتب (أمانات)', 'owner' => 'المالك'][$state] ?? $state)->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => ['recorded' => 'مسجّلة', 'reversed' => 'معكوسة', 'pending_reversal' => 'بانتظار العكس'][$state] ?? $state)
                    ->color(fn ($state) => $state === 'recorded' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('reverse')->label('طلب تعديل/عكس')->color('danger')->visible(fn (Payment $r) => $r->status === 'recorded')
                    ->schema([TextInput::make('reason')->label('السبب')->required()])
                    ->action(fn (Payment $r, array $data) => Ui::attempt(fn () => app(PaymentService::class)->requestReversal($r, auth()->user(), $data['reason']), 'رُفع الطلب للمراجِع')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/'), 'create' => Pages\CreatePayment::route('/create')];
    }
}
