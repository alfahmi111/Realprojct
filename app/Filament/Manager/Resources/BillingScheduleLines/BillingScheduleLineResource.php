<?php

namespace App\Filament\Manager\Resources\BillingScheduleLines;

use App\Filament\Support\Ui;
use App\Finance\InvoiceService;
use App\Models\BillingScheduleLine;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Due schedule lines → rent invoices (REQ-0300, REQ-0574); only for registered/active leases (REQ-0185). */
class BillingScheduleLineResource extends Resource
{
    protected static ?string $model = BillingScheduleLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $modelLabel = 'دفعة مجدولة';

    protected static ?string $pluralModelLabel = 'الدفعات المجدولة';

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

    public static function table(Table $table): Table
    {
        $issue = fn (BillingScheduleLine $l) => app(InvoiceService::class)->issueRent($l, CarbonImmutable::today());
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at')->where('status', 'scheduled')->with('lease.unit')->orderBy('due_on'))
            ->columns([
                TextColumn::make('lease.unit.code')->label('الوحدة'), TextColumn::make('due_on')->label('الاستحقاق')->date()->sortable(),
                TextColumn::make('period_start')->label('من')->date(), TextColumn::make('period_end')->label('إلى')->date(),
                Ui::moneyColumn('amount_minor', 'المبلغ'),
                TextColumn::make('lease.status')->label('حالة العقد'),
            ])
            ->recordActions([Action::make('issue')->label('إصدار فاتورة')->action(fn (BillingScheduleLine $l) => Ui::attempt(fn () => $issue($l), 'صدرت الفاتورة'))])
            ->toolbarActions([BulkAction::make('issueAll')->label('إصدار فواتير للمحدد')->action(fn (Collection $rows) => Ui::attempt(fn () => $rows->each($issue), 'صدرت الفواتير'))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBillingScheduleLines::route('/')];
    }
}
