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
            Select::make('method')->label('الطريقة')->options(['mada' => 'مدى', 'apple_pay' => 'Apple Pay', 'card' => 'بطاقة', 'sadad' => 'سداد', 'bank_transfer' => 'تحويل بنكي', 'cash' => 'نقداً'])->required()->live(),
            DatePicker::make('received_on')->label('تاريخ الاستلام')->required()->default(now()),
            Select::make('paid_to')->label('استُلم في حساب')->options(fn () => app(\App\Leasing\PolicyService::class)->paidToOptions(\App\Support\Dates\Today::date()))->default('owner')->required(),
            // Q38: the reference confirms an electronic collection; it is unique within the source that issued it
            Select::make('ref_source')->label('مصدر رقم العملية')
                ->options(fn (\Filament\Schemas\Components\Utilities\Get $get) => array_intersect_key(PaymentService::SOURCES, array_flip(PaymentService::SOURCES_BY_METHOD[$get('method')] ?? [])))
                ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => in_array($get('method'), PaymentService::ELECTRONIC, true))
                ->helperText('الجهة التي أصدرت الرقم: إيجار، أو سداد، أو البنك، أو بوابة الدفع.'),
            TextInput::make('reference')->label('رقم العملية المرجعي')->maxLength(255)
                ->helperText('كما يظهر في كشف الحساب أو إشعار إيجار. لا تُؤكَّد الدفعة الإلكترونية بدونه: إن تركته فارغاً حُفظت «بانتظار المطابقة» بلا سند قبض ولا أثر مالي حتى تُدخله. ولا تُسجَّل الحركة نفسها مرتين ولو تغيّر المستأجر أو الحساب.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('tenant')->latest('received_on'))
            ->columns([
                TextColumn::make('receipt_number')->label('رقم السند')->searchable()->placeholder('لا سند بعد'), TextColumn::make('received_on')->label('التاريخ')->date()->sortable(),
                TextColumn::make('reference')->label('رقم العملية')->searchable()->placeholder('—')->toggleable()
                    ->description(fn (Payment $r) => $r->ref_source ? (PaymentService::SOURCES[$r->ref_source] ?? ($r->ref_source === 'unspecified' ? 'مصدر غير محدد' : $r->ref_source)) : null),
                TextColumn::make('tenant.name_ar')->label('المستأجر')->searchable()->placeholder('—'),
                Ui::moneyColumn('amount_minor', 'المبلغ'),
                TextColumn::make('method')->label('الطريقة')->formatStateUsing(fn ($state) => ['mada' => 'مدى', 'apple_pay' => 'Apple Pay', 'card' => 'بطاقة', 'sadad' => 'سداد', 'bank_transfer' => 'تحويل بنكي', 'cash' => 'نقداً'][$state] ?? $state),
                TextColumn::make('paid_to')->label('في حساب')->formatStateUsing(fn ($state) => ['manager' => 'المكتب (أمانات)', 'owner' => 'المالك'][$state] ?? $state)->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => ['recorded' => 'مسجّلة', 'reversed' => 'معكوسة', 'pending_reversal' => 'بانتظار العكس', 'pending_match' => 'بانتظار المطابقة'][$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'recorded' => 'success', 'pending_match' => 'warning', default => 'gray' }),
            ])
            ->recordActions([
                // Q38: a payment waiting for its reference is confirmed here — only then it gets a receipt and moves balances
                Action::make('confirmMatch')->label('تأكيد برقم العملية')->color('success')->visible(fn (Payment $r) => $r->status === 'pending_match')
                    ->schema(fn (Payment $r) => [
                        Select::make('ref_source')->label('مصدر رقم العملية')->required()->default($r->ref_source ?? (count(PaymentService::SOURCES_BY_METHOD[$r->method] ?? []) === 1 ? PaymentService::SOURCES_BY_METHOD[$r->method][0] : null))
                            ->options(array_intersect_key(PaymentService::SOURCES, array_flip(PaymentService::SOURCES_BY_METHOD[$r->method] ?? []))),
                        TextInput::make('reference')->label('رقم العملية المرجعي')->required()->default($r->reference),
                    ])
                    ->action(fn (Payment $r, array $data) => Ui::attempt(fn () => app(PaymentService::class)->confirmMatch($r, $data['reference'], $data['ref_source'], auth()->user()), 'أُكّدت الدفعة وصدر سند القبض')),
                Action::make('discardPending')->label('إلغاء (أُدخلت خطأً)')->color('gray')->visible(fn (Payment $r) => $r->status === 'pending_match')
                    ->schema([TextInput::make('reason')->label('السبب')->required()])
                    ->action(fn (Payment $r, array $data) => Ui::attempt(fn () => app(PaymentService::class)->discardPending($r, auth()->user(), $data['reason']), 'أُلغيت')),
                Action::make('reverse')->label('طلب تعديل/عكس')->color('danger')->visible(fn (Payment $r) => $r->status === 'recorded')
                    ->schema([TextInput::make('reason')->label('السبب')->required()])
                    ->action(fn (Payment $r, array $data) => Ui::attempt(fn () => app(PaymentService::class)->requestReversal($r, auth()->user(), $data['reason']), 'رُفع الطلب للمراجِع')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/'), 'create' => Pages\CreatePayment::route('/create')];
    }

    /** The user's property scope (REQ-0494, review M18 A2). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Filament\Support\PropertyScope::apply(parent::getEloquentQuery(), 'payment');
    }
}
