<?php

namespace App\Filament\Manager\Resources\ReconciliationIssues;

use App\Filament\Support\Ui;
use App\Finance\Reconciliation;
use App\Models\ReconciliationIssue;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Compliance work queue from the nightly reconciliation and the Ejar CSV match (REQ-0434, REQ-0435, REQ-0014). */
class ReconciliationIssueResource extends Resource
{
    protected static ?string $model = ReconciliationIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $modelLabel = 'فرق تسوية';

    protected static ?string $pluralModelLabel = 'التسوية ومراقبة الامتثال';

    public const KINDS_AR = ['registered_lease_without_invoice' => 'عقد موثّق بلا فاتورة', 'invoice_without_lease' => 'فاتورة بلا عقد', 'invoice_on_expired_lease' => 'فاتورة على عقد منتهٍ', 'amount_mismatch' => 'مبلغ غير مطابق', 'unallocated_payment' => 'دفعة غير مخصصة', 'ejar_contract_unknown' => 'عقد في إيجار غير موجود عندنا', 'ejar_status_mismatch' => 'حالة مختلفة عن إيجار', 'lease_missing_in_ejar_export' => 'عقد غير موجود في ملف إيجار'];

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
        return $table->modifyQueryUsing(fn ($query) => $query->latest('detected_on'))
            ->columns([
                TextColumn::make('kind')->label('الفرق')->formatStateUsing(fn ($state) => self::KINDS_AR[$state] ?? $state)->badge(),
                TextColumn::make('details')->label('التفاصيل')->formatStateUsing(fn ($state) => json_encode($state, JSON_UNESCAPED_UNICODE))->limit(90),
                TextColumn::make('detected_on')->label('اكتُشف')->date(), TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(['open' => 'مفتوح', 'resolved' => 'محلول'])->default('open')])
            ->headerActions([
                Action::make('run')->label('تشغيل التسوية الآن')->action(fn () => Ui::attempt(fn () => app(Reconciliation::class)->run(CarbonImmutable::today()), 'اكتملت التسوية')),
                Action::make('ejarCsv')->label('مطابقة ملف إيجار (CSV)')
                    ->schema([FileUpload::make('csv')->label('الملف المصدَّر من إيجار')->acceptedFileTypes(['text/csv', 'text/plain'])->disk('local')->directory('ejar-imports')->required()])
                    ->action(fn (array $data) => Ui::attempt(fn () => app(Reconciliation::class)->matchEjarCsv(Storage::disk('local')->path($data['csv']), CarbonImmutable::today()), 'اكتملت المطابقة')),
            ])
            ->recordActions([
                Action::make('resolve')->label('معالجة')->visible(fn (ReconciliationIssue $r) => $r->status === 'open')
                    ->schema([TextInput::make('note')->label('ما تم')->required()])
                    ->action(fn (ReconciliationIssue $r, array $data) => Ui::attempt(fn () => app(Reconciliation::class)->resolve($r, auth()->id(), $data['note']))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReconciliationIssues::route('/')];
    }
}
