<?php

namespace App\Filament\Manager\Resources\LeaseOffers;

use App\Filament\Support\Ui;
use App\Leasing\LeasingService;
use App\Models\LeasableUnit;
use App\Models\LeaseOffer;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Lease offers (REQ-0184, REQ-0317): create → submit for approvals → approve/reject → send link → tenant accepts. */
class LeaseOfferResource extends Resource
{
    protected static ?string $model = LeaseOffer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentPlus;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $modelLabel = 'عرض تأجير';

    protected static ?string $pluralModelLabel = 'عروض التأجير';

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
            Select::make('leasable_unit_id')->label('الوحدة')->options(fn () => LeasableUnit::whereNull('archived_at')->orderBy('code')->get()->mapWithKeys(fn ($u) => [$u->id => $u->code . ' — ' . $u->name_ar]))->searchable()->required(),
            Select::make('tenant_id')->label('المستأجر')->options(fn () => Tenant::whereNull('archived_at')->pluck('name_ar', 'id'))->searchable()->required(),
            Select::make('usage')->label('الاستخدام')->options(['residential' => 'سكني', 'commercial' => 'تجاري'])->required(),
            DatePicker::make('starts_on')->label('البداية')->required(),
            Select::make('duration_unit')->label('وحدة المدة')->options(['month' => 'شهر', 'year' => 'سنة'])->required(),
            TextInput::make('duration_count')->label('العدد')->integer()->minValue(1)->required(),
            Ui::money('annual_rent_minor', 'الإيجار السنوي')->required(),
            Select::make('payment_frequency')->label('الدفعات')->options(['monthly' => 'شهرية', 'quarterly' => 'ربع سنوية', 'semiannual' => 'نصف سنوية', 'annual' => 'سنوية', 'single' => 'دفعة واحدة'])->required(),
            Ui::money('deposit_minor', 'التأمين'),
            DatePicker::make('valid_until')->label('صالح حتى'),
            Repeater::make('escalations')->label('الزيادات المجدولة')->schema([
                Select::make('kind')->label('النوع')->options(['percentage' => 'نسبة', 'amount' => 'مبلغ'])->required()->live(),
                TextInput::make('percentage_bp')->label('النسبة (نقطة أساس، 500 = 5%)')->integer()->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('kind') === 'percentage'),
                Ui::money('amount_minor', 'المبلغ السنوي')->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('kind') === 'amount'),
                TextInput::make('contract_year')->label('من سنة العقد')->integer()->minValue(2),
                DatePicker::make('effective_on')->label('أو من تاريخ'),
            ])->columns(3)->defaultItems(0)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('unit')->latest())
            ->columns([
                TextColumn::make('id')->label('#'), TextColumn::make('unit.code')->label('الوحدة'),
                Ui::moneyColumn('annual_rent_minor', 'الإيجار السنوي'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('starts_on')->label('البداية')->date(),
            ])
            ->recordActions([
                Action::make('submit')->label('رفع للاعتماد')->visible(fn (LeaseOffer $r) => $r->status === 'draft')
                    ->action(fn (LeaseOffer $r) => Ui::attempt(fn () => app(LeasingService::class)->submitOffer($r))),
                Action::make('approve')->label('اعتماد')->color('success')->visible(fn (LeaseOffer $r) => $r->status === 'pending_approval')
                    ->action(fn (LeaseOffer $r) => Ui::attempt(fn () => app(LeasingService::class)->decideOffer($r, auth()->user(), true))),
                Action::make('reject')->label('رفض')->color('danger')->visible(fn (LeaseOffer $r) => $r->status === 'pending_approval')
                    ->schema([TextInput::make('reason')->label('السبب')->required()])
                    ->action(fn (LeaseOffer $r, array $data) => Ui::attempt(fn () => app(LeasingService::class)->decideOffer($r, auth()->user(), false, $data['reason']))),
                Action::make('send')->label('إرسال للمستأجر')->visible(fn (LeaseOffer $r) => $r->status === 'approved')
                    ->action(function (LeaseOffer $r) {
                        $token = Ui::attempt(fn () => app(LeasingService::class)->sendOffer($r), 'أُرسل العرض');
                        if ($token) {
                            Notification::make()->title('رابط قبول العرض')->body(route('offer.accept', $token))->persistent()->send();
                        }
                    }),
                Action::make('expire')->label('إنهاء العرض')->color('gray')->visible(fn (LeaseOffer $r) => in_array($r->status, ['sent', 'approved'], true))
                    ->schema([TextInput::make('reason')->label('سبب خسارة المستأجر')->required()])
                    ->action(fn (LeaseOffer $r, array $data) => Ui::attempt(fn () => app(LeasingService::class)->expireOffer($r, $data['reason']))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLeaseOffers::route('/'), 'create' => Pages\CreateLeaseOffer::route('/create')];
    }
}
