<?php

namespace App\Filament\Manager\Resources\Invoices;

use App\Filament\Support\Ui;
use App\Finance\InvoiceService;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Invoices with their tax-treatment code per line (REQ-0420, REQ-0424); cancellation through maker/checker (REQ-0069). */
class InvoiceResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('finance.view');
    }
    protected static ?string $model = Invoice::class;

    protected static ?string $recordTitleAttribute = 'number';   // global search in the header

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $modelLabel = 'فاتورة';

    protected static ?string $pluralModelLabel = 'الفواتير';

    public const STATUS_AR = ['issued' => 'صادرة', 'cancelled' => 'ملغاة', 'draft' => 'مسودة'];

    public const EINVOICE_AR = ['pending' => 'بانتظار الإرسال', 'reported' => 'مُبلَّغ عنها', 'cleared' => 'معتمدة', 'rejected' => 'مرفوضة'];

    /** VAT treatment codes (policies table) in words (review M18 S11). */
    public const TAX_AR = [
        \App\Finance\VatEngine::EXEMPT_RESIDENTIAL => 'إيجار سكني معفى',
        \App\Finance\VatEngine::STANDARD_COMMERCIAL => 'إيجار تجاري خاضع للنسبة الأساسية',
        \App\Finance\VatEngine::OUT_OF_SCOPE_UNREGISTERED => 'خارج نطاق الضريبة (مؤجر غير مسجل)',
        \App\Finance\VatEngine::STANDARD_MANAGEMENT_FEE => 'أتعاب إدارة خاضعة للنسبة الأساسية',
        \App\Finance\VatEngine::STANDARD_MAINTENANCE => 'تحميل صيانة خاضع للنسبة الأساسية',
    ];

    public const KIND_AR = ['rent' => 'إيجار', 'maintenance_recharge' => 'تحميل صيانة', 'service' => 'خدمة', 'management_fee' => 'أتعاب إدارة'];

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
        $m = fn ($v) => \App\Support\Dates\NumberFormatter::money($v);
        return $schema->components([
            Section::make('الفاتورة')->schema([
                TextEntry::make('number')->label('الرقم'), TextEntry::make('issue_date')->label('التاريخ')->date(), TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::STATUS_AR[$state] ?? $state)->color(fn ($state) => $state === 'cancelled' ? 'gray' : 'success'),
                TextEntry::make('kind')->label('النوع')->formatStateUsing(fn ($state) => self::KIND_AR[$state] ?? $state),
                TextEntry::make('ejar_contract_number')->label('رقم عقد إيجار'), TextEntry::make('unit_code')->label('الوحدة'), TextEntry::make('buyer_vat_number')->label('الرقم الضريبي للمشتري'),
                TextEntry::make('period_start')->label('الفترة من')->date(), TextEntry::make('period_end')->label('إلى')->date(),
                TextEntry::make('einvoice_status')->label('حالة الفوترة الإلكترونية')->formatStateUsing(fn ($state) => self::EINVOICE_AR[$state] ?? $state)->placeholder('—'),
                TextEntry::make('sadad_bill_number')->label('رقم سداد')->placeholder('—'),
                TextEntry::make('subtotal_minor')->label('الصافي')->formatStateUsing($m), TextEntry::make('tax_minor')->label('الضريبة')->formatStateUsing($m), TextEntry::make('total_minor')->label('الإجمالي')->formatStateUsing($m),
            ])->columns(3),
            Section::make('البنود')->schema([
                RepeatableEntry::make('lines')->label('')->schema([
                    TextEntry::make('description_ar')->label('البند'), TextEntry::make('tax_code')->label('المعاملة الضريبية')->formatStateUsing(fn ($state) => self::TAX_AR[$state] ?? $state),
                    TextEntry::make('net_minor')->label('الصافي')->formatStateUsing($m), TextEntry::make('tax_minor')->label('الضريبة')->formatStateUsing($m),
                ])->columns(4),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        // the paid sum comes with the page's query (one query, not one per row and column — review M18 S-performance)
        $out = fn (Invoice $r) => $r->total_minor->minor() - (int) ($r->paid_minor ?? app(InvoiceService::class)->outstanding($r)->negate()->plus($r->total_minor)->minor());
        $pay = fn (Invoice $r) => $r->status === 'cancelled' ? 'cancelled' : (($o = $out($r)) <= 0 ? 'paid' : ($o < $r->total_minor->minor() ? 'partial' : 'unpaid'));
        return $table->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'property'])
                ->withSum(['allocations as paid_minor' => fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('status', 'recorded'))], 'amount_minor')
                ->latest('issue_date')->latest('id'))
            ->columns([
                TextColumn::make('number')->label('الفاتورة')->searchable()->weight('bold')
                    ->description(fn (Invoice $r) => (self::KIND_AR[$r->kind] ?? $r->kind) . ($r->sadad_bill_number ? '، سداد ' . $r->sadad_bill_number : '')),
                TextColumn::make('tenant.name_ar')->label('العميل')->searchable()->placeholder('—')
                    // implode, not trim(): trim() works on bytes and cuts Arabic letters that share a lead byte with «،»
                    ->description(fn (Invoice $r) => implode('، ', array_filter([$r->unit_code ? 'الوحدة ' . $r->unit_code : null, $r->property?->name_ar]))),
                TextColumn::make('issue_date')->label('التاريخ')->date('Y-m-d')->sortable()
                    ->description(fn (Invoice $r) => $r->period_start ? 'عن ' . \Carbon\CarbonImmutable::parse($r->period_start)->format('Y-m') : null),
                Ui::moneyColumn('total_minor', 'الإجمالي'),
                TextColumn::make('outstanding')->label('المتبقي')->state(fn (Invoice $r) => $out($r))
                    ->formatStateUsing(fn ($state) => (int) $state > 0 ? \App\Support\Dates\NumberFormatter::money((int) $state) : '—')
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('pay')->label('السداد')->badge()->state($pay)
                    ->formatStateUsing(fn ($state) => ['paid' => 'مسددة', 'partial' => 'مسددة جزئياً', 'unpaid' => 'غير مسددة', 'cancelled' => 'ملغاة'][$state])
                    ->color(fn ($state) => ['paid' => 'success', 'partial' => 'warning', 'unpaid' => 'danger', 'cancelled' => 'gray'][$state]),
                TextColumn::make('einvoice_status')->label('الفوترة الإلكترونية')->badge()->color('gray')->toggleable()
                    ->formatStateUsing(fn ($state) => self::EINVOICE_AR[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(['issued' => 'صادرة', 'cancelled' => 'ملغاة']),
                SelectFilter::make('kind')->label('النوع')->options(self::KIND_AR),
                SelectFilter::make('property_id')->label('العقار')->relationship('property', 'name_ar'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('sadad')->label('رقم سداد')->icon(\Filament\Support\Icons\Heroicon::OutlinedBanknotes)->color('gray')
                    ->visible(fn (Invoice $r) => $r->status === 'issued' && ! $r->sadad_bill_number && app(\App\Finance\SadadBilling::class)->available() && app(InvoiceService::class)->outstanding($r)->isPositive())
                    ->requiresConfirmation()->modalDescription('يصل للمستأجر رقم يدفع به من تطبيق بنكه، ويذهب المبلغ إلى حساب المفوتر الخاص بالمكتب أو المالك.')
                    ->action(fn (Invoice $r) => Ui::attempt(fn () => app(\App\Finance\SadadBilling::class)->issue($r), 'صدر رقم سداد')),
                Action::make('cancel')->label('طلب إلغاء')->color('danger')->visible(fn (Invoice $r) => $r->status === 'issued')
                    ->schema([TextInput::make('reason')->label('السبب')->required()])
                    ->action(fn (Invoice $r, array $data) => Ui::attempt(fn () => app(InvoiceService::class)->requestCancellation($r, auth()->user(), $data['reason']), 'رُفع طلب الإلغاء للمراجِع')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInvoices::route('/'), 'view' => Pages\ViewInvoice::route('/{record}')];
    }

    /** The user's property scope (REQ-0494, review M18 A2). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Filament\Support\PropertyScope::apply(parent::getEloquentQuery(), 'property_id');
    }
}
