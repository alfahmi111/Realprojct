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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $modelLabel = 'فاتورة';

    protected static ?string $pluralModelLabel = 'الفواتير';

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
                TextEntry::make('number')->label('الرقم'), TextEntry::make('issue_date')->label('التاريخ')->date(), TextEntry::make('status')->label('الحالة')->badge(),
                TextEntry::make('ejar_contract_number')->label('رقم عقد إيجار'), TextEntry::make('unit_code')->label('الوحدة'), TextEntry::make('buyer_vat_number')->label('الرقم الضريبي للمشتري'),
                TextEntry::make('period_start')->label('الفترة من')->date(), TextEntry::make('period_end')->label('إلى')->date(),
                TextEntry::make('einvoice_status')->label('حالة الفوترة الإلكترونية'),
                TextEntry::make('subtotal_minor')->label('الصافي')->formatStateUsing($m), TextEntry::make('tax_minor')->label('الضريبة')->formatStateUsing($m), TextEntry::make('total_minor')->label('الإجمالي')->formatStateUsing($m),
            ])->columns(3),
            Section::make('البنود')->schema([
                RepeatableEntry::make('lines')->label('')->schema([
                    TextEntry::make('description_ar')->label('البند'), TextEntry::make('tax_code')->label('رمز المعاملة الضريبية'),
                    TextEntry::make('net_minor')->label('الصافي')->formatStateUsing($m), TextEntry::make('tax_minor')->label('الضريبة')->formatStateUsing($m),
                ])->columns(4),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->latest('issue_date'))
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(), TextColumn::make('kind')->label('النوع'), TextColumn::make('unit_code')->label('الوحدة'),
                TextColumn::make('issue_date')->label('التاريخ')->date()->sortable(), Ui::moneyColumn('total_minor', 'الإجمالي'),
                TextColumn::make('outstanding')->label('المتبقي')->state(fn (Invoice $r) => app(InvoiceService::class)->outstanding($r)->toDecimalString()),
                TextColumn::make('status')->label('الحالة')->badge(), TextColumn::make('einvoice_status')->label('الفوترة الإلكترونية')->badge(),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(['issued' => 'صادرة', 'cancelled' => 'ملغاة'])])
            ->recordActions([
                ViewAction::make(),
                Action::make('cancel')->label('طلب إلغاء')->color('danger')->visible(fn (Invoice $r) => $r->status === 'issued')
                    ->schema([TextInput::make('reason')->label('السبب')->required()])
                    ->action(fn (Invoice $r, array $data) => Ui::attempt(fn () => app(InvoiceService::class)->requestCancellation($r, auth()->user(), $data['reason']), 'رُفع طلب الإلغاء للمراجِع')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInvoices::route('/'), 'view' => Pages\ViewInvoice::route('/{record}')];
    }
}
