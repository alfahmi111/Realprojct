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

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('finance.view');
    }
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
        $dates = app(\App\Support\Dates\DateService::class);
        $today = CarbonImmutable::today();
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at')->where('status', 'scheduled')->with(['lease.unit.property', 'lease.tenant'])->orderBy('due_on'))
            // grouped by property, as a tree: property → unit + tenant → its scheduled payments (owner request 2026-11-23)
            ->groups([
                \Filament\Tables\Grouping\Group::make('lease.unit.property.name_ar')->label('العقار')->collapsible(),
                \Filament\Tables\Grouping\Group::make('lease.tenant.name_ar')->label('المستأجر')->collapsible(),
            ])->defaultGroup('lease.unit.property.name_ar')
            ->columns([
                TextColumn::make('lease.unit.property.name_ar')->label('العقار')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lease.unit.code')->label('الوحدة')->searchable()->weight('bold')
                    ->url(fn (BillingScheduleLine $l) => \App\Filament\Manager\Resources\Leases\LeaseResource::getUrl('view', ['record' => $l->lease_id])),
                TextColumn::make('lease.tenant.name_ar')->label('المستأجر')->searchable(),
                TextColumn::make('due_on')->label('الاستحقاق')->sortable()
                    ->formatStateUsing(fn ($state) => $dates->formatDate($state, 'gregorian'))
                    ->description(fn (BillingScheduleLine $l) => $dates->formatDate($l->due_on, 'hijri')),
                TextColumn::make('days')->label('المتبقي')->badge()
                    ->state(fn (BillingScheduleLine $l) => (int) $today->diffInDays(CarbonImmutable::parse($l->due_on), false))
                    ->formatStateUsing(fn (int $state) => $state < 0 ? 'مستحقة منذ ' . abs($state) . ' يوماً' : ($state === 0 ? 'اليوم' : 'بعد ' . $state . ' يوماً'))
                    ->color(fn (int $state) => $state < 0 ? 'danger' : ($state <= 15 ? 'warning' : 'gray')),
                TextColumn::make('period_start')->label('الفترة')->formatStateUsing(fn ($state, BillingScheduleLine $l) => CarbonImmutable::parse($l->period_start)->format('Y-m-d') . ' ← ' . CarbonImmutable::parse($l->period_end)->format('Y-m-d'))->toggleable(),
                Ui::moneyColumn('amount_minor', 'المبلغ'),
                TextColumn::make('lease.status')->label('حالة العقد')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => \App\Filament\Manager\Resources\Leases\LeaseResource::STATUS_AR[$state] ?? $state),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('property')->label('العقار')
                    ->options(fn () => \App\Models\Property::whereNull('archived_at')->pluck('name_ar', 'id'))
                    ->query(fn ($query, array $data) => $data['value'] ? $query->whereHas('lease.unit', fn ($q) => $q->where('property_id', $data['value'])) : $query),
                \Filament\Tables\Filters\SelectFilter::make('when')->label('الاستحقاق')
                    ->options(['due' => 'مستحقة الآن', '30' => 'خلال 30 يوماً', '90' => 'خلال 90 يوماً'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'due' => $query->where('due_on', '<=', $today->toDateString()),
                        '30', '90' => $query->where('due_on', '<=', $today->addDays((int) $data['value'])->toDateString()),
                        default => $query,
                    }),
            ])
            ->recordActions([Action::make('issue')->label('إصدار فاتورة')->action(fn (BillingScheduleLine $l) => Ui::attempt(fn () => $issue($l), 'صدرت الفاتورة'))])
            ->toolbarActions([BulkAction::make('issueAll')->label('إصدار فواتير للمحدد')->action(fn (Collection $rows) => Ui::attempt(fn () => $rows->each($issue), 'صدرت الفواتير'))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBillingScheduleLines::route('/')];
    }
}
