<?php

namespace App\Filament\Manager\Resources\Leases;

use App\Filament\Support\Ui;
use App\Finance\PaymentService;
use App\Leasing\LeasingService;
use App\Leasing\PolicyService;
use App\Models\Lease;
use App\Support\Money\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Leases and their lifecycle (spec §5.4, §10.1 manual-assisted Ejar). Leases come from accepted offers or imports. */
class LeaseResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('leasing.view');
    }
    protected static ?string $model = Lease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $modelLabel = 'عقد';

    protected static ?string $pluralModelLabel = 'العقود';

    public const STATUS_AR = ['draft' => 'مسودة', 'sent_for_registration' => 'مرسل للتوثيق', 'awaiting_parties' => 'بانتظار موافقة الأطراف', 'registered' => 'موثّق', 'active' => 'ساري', 'notice_given' => 'أُشعر بعدم التجديد', 'expired' => 'منتهٍ', 'renewed' => 'مُجدَّد', 'settled_closed' => 'مخالصة وإغلاق', 'cancelled' => 'ملغى'];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('العقد')->schema([
                TextEntry::make('unit.code')->label('الوحدة'), TextEntry::make('tenant.name_ar')->label('المستأجر'),
                TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::STATUS_AR[$state] ?? $state)->badge(),
                TextEntry::make('ejar_contract_number')->label('رقم عقد إيجار'), TextEntry::make('starts_on')->label('البداية')->date(), TextEntry::make('ends_on')->label('النهاية')->date(),
                TextEntry::make('annual_rent_minor')->label('الإيجار السنوي')->formatStateUsing(fn ($state) => \App\Support\Dates\NumberFormatter::money($state)),
                TextEntry::make('notice')->label('موعد مهلة الإشعار')->state(fn (Lease $r) => app(LeasingService::class)->noticeDeadline($r)->toDateString()),
            ])->columns(4),
            Section::make('جدول الدفعات')->schema([
                RepeatableEntry::make('scheduleLines')->label('')->schema([
                    TextEntry::make('due_on')->label('الاستحقاق')->date(), TextEntry::make('period_end')->label('حتى')->date(),
                    TextEntry::make('amount_minor')->label('المبلغ')->formatStateUsing(fn ($state) => $state->toDecimalString()), TextEntry::make('status')->label('الحالة'),
                ])->columns(4),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $svc = fn () => app(LeasingService::class);
        return $table->modifyQueryUsing(fn ($query) => $query->with(['unit', 'tenant'])->latest())
            ->columns([
                TextColumn::make('unit.code')->label('الوحدة')->searchable(), TextColumn::make('tenant.name_ar')->label('المستأجر')->searchable(),
                TextColumn::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::STATUS_AR[$state] ?? $state)->badge(),
                TextColumn::make('ejar_contract_number')->label('رقم إيجار'), TextColumn::make('ends_on')->label('النهاية')->date()->sortable(),
                Ui::moneyColumn('annual_rent_minor', 'الإيجار السنوي'),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(self::STATUS_AR)])
            ->recordActions([
                ViewAction::make(),
                Action::make('sendForRegistration')->label('إرسال للتوثيق')->visible(fn (Lease $r) => $r->status === 'draft')
                    ->action(function (Lease $r) use ($svc) {
                        $pkg = Ui::attempt(fn () => $svc()->sendForRegistration($r), 'جُهّزت بيانات التوثيق');
                        if ($pkg) {
                            Notification::make()->title('سجّل العقد في منصة إيجار بهذه البيانات')->body(json_encode($pkg['data'], JSON_UNESCAPED_UNICODE))->persistent()->send();
                        }
                    }),
                Action::make('awaiting')->label('بانتظار الأطراف')->visible(fn (Lease $r) => $r->status === 'sent_for_registration')
                    ->action(fn (Lease $r) => Ui::attempt(fn () => $svc()->markAwaitingParties($r))),
                Action::make('register')->label('إدخال رقم العقد الموثّق')->color('success')->visible(fn (Lease $r) => $r->status === 'awaiting_parties')
                    ->schema([TextInput::make('number')->label('رقم عقد إيجار')->required()])
                    ->action(fn (Lease $r, array $data) => Ui::attempt(fn () => $svc()->register($r, $data['number']), 'تم التوثيق')),
                Action::make('activate')->label('تفعيل')->visible(fn (Lease $r) => $r->status === 'registered')
                    ->action(fn (Lease $r) => Ui::attempt(fn () => $svc()->activate($r))),
                Action::make('deposit')->label('تسجيل التأمين')->visible(fn (Lease $r) => in_array($r->status, ['registered', 'active'], true) && $r->deposit_minor->isPositive())
                    ->schema([DatePicker::make('on')->label('التاريخ')->required()->default(now()), Select::make('paid_to')->label('استُلم في حساب')->options(['manager' => 'المدير (أمانات)', 'owner' => 'المالك'])->required()])
                    ->action(fn (Lease $r, array $data) => Ui::attempt(fn () => app(PaymentService::class)->recordDeposit($r, $r->deposit_minor, CarbonImmutable::parse($data['on']), $data['paid_to']), 'سُجّل التأمين كالتزام')),
                Action::make('notice')->label('إشعار عدم التجديد')->color('warning')->visible(fn (Lease $r) => $r->status === 'active')
                    ->schema(fn (Lease $r) => [
                        Select::make('by')->label('من')->options(['tenant' => 'المستأجر', 'lessor' => 'المؤجر'])->required()->live(),
                        DatePicker::make('on')->label('تاريخ الإشعار')->required()->default(now()),
                        Select::make('reason')->label('سبب الرفض (المؤجر)')->options(fn () => app(PolicyService::class)->nonRenewalReasons($r->unit->property, CarbonImmutable::now())->pluck('title_ar', 'code'))->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('by') === 'lessor'),
                        FileUpload::make('document')->label('المستند الداعم')->directory('subscribers/' . $r->subscriber_id . '/notices')->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('by') === 'lessor'),
                    ])
                    ->action(fn (Lease $r, array $data) => Ui::attempt(fn () => $svc()->giveNotice($r, $data['by'], CarbonImmutable::parse($data['on']), $data['reason'] ?? null, $data['document'] ?? null), 'سُجّل الإشعار')),
                Action::make('renew')->label('تجديد')->visible(fn (Lease $r) => in_array($r->status, ['active', 'expired'], true))
                    ->schema([Ui::money('rent', 'الإيجار السنوي الجديد')->required(), TextInput::make('years')->label('سنوات')->integer()->minValue(1)->default(1)->required()])
                    ->action(fn (Lease $r, array $data) => Ui::attempt(fn () => $svc()->renew($r, Money::of((int) $data['rent']), (int) $data['years']), 'تم التجديد')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLeases::route('/'), 'view' => Pages\ViewLease::route('/{record}')];
    }
}
