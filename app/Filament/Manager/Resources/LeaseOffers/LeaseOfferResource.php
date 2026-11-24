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

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('leasing.view');
    }

    /** Write gate (review M18): viewing roles do not create. */
    public static function canCreate(): bool
    {
        return \App\Filament\Support\Ui::allowed('leasing.edit');
    }
    protected static ?string $model = LeaseOffer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentPlus;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $modelLabel = 'عرض تأجير';

    protected static ?string $pluralModelLabel = 'عروض التأجير';
    public const STATUS_AR = ['draft' => 'مسودة', 'pending_approval' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'sent' => 'أُرسل للمستأجر', 'accepted' => 'قَبِله المستأجر', 'rejected' => 'مرفوض', 'expired' => 'انتهى'];

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
            \Filament\Schemas\Components\Section::make('الوحدة والمستأجر')->description('اختر الوحدة ثم المستأجر. إن لم يكن مسجّلاً بعد فأضفه من «المستأجرون».')->schema([
                Select::make('leasable_unit_id')->label('الوحدة')->options(fn () => LeasableUnit::whereNull('archived_at')->with('property')->orderBy('code')->get()->mapWithKeys(fn ($u) => [$u->id => $u->code . ' — ' . $u->name_ar . ($u->property ? '، ' . $u->property->name_ar : '')]))->searchable()->required(),
                Select::make('tenant_id')->label('المستأجر')->options(fn () => Tenant::whereNull('archived_at')->orderBy('name_ar')->pluck('name_ar', 'id'))->searchable()->required(),
                \Filament\Forms\Components\ToggleButtons::make('usage')->label('الغرض')->options(['residential' => 'سكني', 'commercial' => 'تجاري'])->inline()->required()->helperText('التجاري تُحتسب عليه ضريبة 15% إن كان المؤجر مسجّلاً.'),
            ])->columns(3)->columnSpanFull(),
            \Filament\Schemas\Components\Section::make('المدة')->schema([
                DatePicker::make('starts_on')->label('تاريخ البداية')->required(),
                TextInput::make('duration_count')->label('المدة')->integer()->minValue(1)->required()->default(1),
                \Filament\Forms\Components\ToggleButtons::make('duration_unit')->label('بالـ')->options(['month' => 'أشهر', 'year' => 'سنوات'])->inline()->required()->default('year'),
                DatePicker::make('valid_until')->label('العرض صالح حتى')->helperText('بعده لا يستطيع المستأجر قبول العرض.'),
            ])->columns(4)->columnSpanFull(),
            \Filament\Schemas\Components\Section::make('المبلغ والدفعات')->description('إن كان في الإيجار خصم عن السعر المطلوب يتجاوز قواعد الاعتماد، رُفع العرض للاعتماد.')->schema([
                Ui::money('annual_rent_minor', 'الإيجار السنوي')->required(),
                Select::make('payment_frequency')->label('دورة السداد')->options(['monthly' => 'شهرية', 'quarterly' => 'ربع سنوية', 'semiannual' => 'نصف سنوية', 'annual' => 'سنوية', 'single' => 'دفعة واحدة'])->required(),
                Ui::money('deposit_minor', 'مبلغ الضمان')->helperText('لا يدخل في قيمة العقد، ويُسجَّل التزاماً حتى يُرد.'),
            ])->columns(3)->columnSpanFull(),
            Repeater::make('escalations')->label('الزيادات المجدولة')->schema([
                Select::make('kind')->label('النوع')->options(['percentage' => 'نسبة', 'amount' => 'مبلغ'])->required()->live(),
                TextInput::make('percentage')->label('النسبة %')->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('kind') === 'percentage'),
                Ui::money('amount_minor', 'المبلغ السنوي')->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('kind') === 'amount'),
                TextInput::make('contract_year')->label('من سنة العقد')->integer()->minValue(2),
                DatePicker::make('effective_on')->label('أو من تاريخ'),
            ])->columns(3)->defaultItems(0)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $step = function (LeaseOffer $r) {
            if ($r->status !== 'pending_approval') {
                return null;
            }
            $all = $r->approvals->sortBy('step');
            $cur = $all->firstWhere('decision', null);
            return $cur ? 'الخطوة ' . $cur->step . ' من ' . $all->count() . '، بانتظار ' . (\App\Authorization\Role::tryFrom($cur->approver_role)?->labelAr() ?? $cur->approver_role) : null;
        };
        return $table->modifyQueryUsing(fn ($query) => $query->with(['unit.property', 'tenant', 'approvals'])->latest())
            ->columns([
                TextColumn::make('tenant.name_ar')->label('العرض')->searchable()->weight('bold')
                    ->description(fn (LeaseOffer $r) => 'الوحدة ' . $r->unit?->code . '، ' . $r->unit?->property?->name_ar),
                TextColumn::make('term')->label('المدة')->state(fn (LeaseOffer $r) => $r->duration_unit === 'year'
                        ? [1 => 'سنة', 2 => 'سنتان'][$r->duration_count] ?? $r->duration_count . ' سنوات'
                        : [1 => 'شهر', 2 => 'شهران'][$r->duration_count] ?? $r->duration_count . ($r->duration_count <= 10 ? ' أشهر' : ' شهراً'))
                    ->description(fn (LeaseOffer $r) => 'تبدأ ' . $r->starts_on?->format('Y-m-d')),
                Ui::moneyColumn('annual_rent_minor', 'الإيجار السنوي'),
                TextColumn::make('discount')->label('عن السعر المطلوب')->placeholder('—')
                    ->state(function (LeaseOffer $r) {
                        $listed = $r->listed_annual_rent_minor?->minor();
                        $rent = $r->annual_rent_minor->minor();
                        if (! $listed || $listed === $rent) {
                            return $listed ? 'بالسعر نفسه' : null;
                        }
                        $pct = round(abs($listed - $rent) * 100 / $listed, 1);
                        return ($rent < $listed ? 'خصم ' : 'زيادة ') . $pct . '%';
                    })
                    ->color(fn ($state) => str_starts_with((string) $state, 'خصم') ? 'warning' : 'gray'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUS_AR[$state] ?? $state)
                    ->color(fn ($state) => ['draft' => 'gray', 'pending_approval' => 'warning', 'approved' => 'info', 'sent' => 'info', 'accepted' => 'success', 'rejected' => 'danger', 'expired' => 'gray'][$state] ?? 'gray')
                    ->description($step),
                TextColumn::make('valid_until')->label('صالح حتى')->placeholder('—')
                    ->state(fn (LeaseOffer $r) => in_array($r->status, ['draft', 'pending_approval', 'approved', 'sent'], true) ? $r->valid_until : null)
                    ->formatStateUsing(fn ($state) => ($d = (int) now()->startOfDay()->diffInDays($state, false)) < 0 ? 'انتهت صلاحيته' : ($d === 0 ? 'اليوم' : 'باقٍ ' . $d . ' يوماً'))
                    ->description(fn (LeaseOffer $r) => $r->valid_until?->format('Y-m-d')),
            ])
            ->filters([\Filament\Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(self::STATUS_AR)])
            ->recordActions([
                Action::make('submit')->authorize(fn () => \App\Filament\Support\Ui::allowed('leasing.edit'))->label('رفع للاعتماد')->visible(fn (LeaseOffer $r) => $r->status === 'draft')
                    ->action(fn (LeaseOffer $r) => Ui::attempt(fn () => app(LeasingService::class)->submitOffer($r))),
                Action::make('approve')->label('اعتماد')->color('success')->visible(fn (LeaseOffer $r) => $r->status === 'pending_approval')
                    ->action(fn (LeaseOffer $r) => Ui::attempt(fn () => app(LeasingService::class)->decideOffer($r, auth()->user(), true))),
                Action::make('reject')->label('رفض')->color('danger')->visible(fn (LeaseOffer $r) => $r->status === 'pending_approval')
                    ->schema([TextInput::make('reason')->label('السبب')->required()])
                    ->action(fn (LeaseOffer $r, array $data) => Ui::attempt(fn () => app(LeasingService::class)->decideOffer($r, auth()->user(), false, $data['reason']))),
                Action::make('send')->authorize(fn () => \App\Filament\Support\Ui::allowed('leasing.edit'))->label('إرسال للمستأجر')->visible(fn (LeaseOffer $r) => $r->status === 'approved')
                    ->action(function (LeaseOffer $r) {
                        $token = Ui::attempt(fn () => app(LeasingService::class)->sendOffer($r), 'أُرسل العرض');
                        if ($token) {
                            Notification::make()->title('رابط قبول العرض')->body(route('offer.accept', $token))->persistent()->send();
                        }
                    }),
                Action::make('expire')->authorize(fn () => \App\Filament\Support\Ui::allowed('leasing.edit'))->label('إنهاء العرض')->color('gray')->visible(fn (LeaseOffer $r) => in_array($r->status, ['sent', 'approved'], true))
                    ->schema([TextInput::make('reason')->label('سبب خسارة المستأجر')->required()])
                    ->action(fn (LeaseOffer $r, array $data) => Ui::attempt(fn () => app(LeasingService::class)->expireOffer($r, $data['reason']))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLeaseOffers::route('/'), 'create' => Pages\CreateLeaseOffer::route('/create')];
    }
}
