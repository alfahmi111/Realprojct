<?php

namespace App\Filament\Manager\Resources\ApprovalRequests;

use App\Approvals\ApprovalExecutor;
use App\Filament\Support\Ui;
use App\Models\ApprovalRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Maker/checker queue (REQ-0069, REQ-0315): another user with higher authority approves; nobody approves their own entry. */
class ApprovalRequestResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('finance.view');
    }
    protected static ?string $model = ApprovalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $modelLabel = 'طلب اعتماد';

    protected static ?string $pluralModelLabel = 'الموافقات المزدوجة';

    public const ACTIONS_AR = ['invoice.cancel' => 'إلغاء فاتورة', 'invoice.discount' => 'خصم', 'invoice.waiver' => 'إعفاء', 'payment.edit' => 'تعديل دفعة', 'trust.disburse' => 'صرف من أمانات الملاك', 'deposit.refund' => 'رد التأمين'];

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

    public static function getNavigationBadge(): ?string
    {
        $n = ApprovalRequest::where('status', 'pending')->count();
        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('maker')->latest())
            ->emptyStateHeading('لا قيود تنتظر المراجعة')->emptyStateDescription('إلغاء فاتورة أو تعديل دفعة أو صرف من الأمانات يدخله موظف ويعتمده موظف آخر. ما ينتظر اعتمادك يظهر هنا.')
            ->columns([
                TextColumn::make('action')->label('الإجراء')->formatStateUsing(fn ($state) => self::ACTIONS_AR[$state] ?? $state),
                TextColumn::make('maker.name')->label('المُدخِل'),
                TextColumn::make('payload')->label('التفاصيل')->formatStateUsing(fn ($state) => \App\Filament\Support\Ui::details((array) $state))->wrap(),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => ['pending' => 'بانتظار المراجِع', 'approved' => 'معتمد', 'rejected' => 'مرفوض'][$state] ?? $state)->color(fn ($state) => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'), TextColumn::make('created_at')->label('التاريخ')->dateTime(),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(['pending' => 'معلّق', 'approved' => 'معتمد', 'rejected' => 'مرفوض'])->default('pending')])
            ->recordActions([
                Action::make('approve')->label('اعتماد')->color('success')->requiresConfirmation()
                    ->visible(fn (ApprovalRequest $r) => $r->status === 'pending' && $r->maker_id !== auth()->id())
                    ->action(fn (ApprovalRequest $r) => Ui::attempt(fn () => app(ApprovalExecutor::class)->approve($r, auth()->user()), 'اعتُمد ونُفّذ')),
                Action::make('reject')->label('رفض')->color('danger')->visible(fn (ApprovalRequest $r) => $r->status === 'pending' && $r->maker_id !== auth()->id())
                    ->schema([TextInput::make('reason')->label('السبب')->required()])
                    ->action(fn (ApprovalRequest $r, array $data) => Ui::attempt(fn () => app(ApprovalExecutor::class)->reject($r, auth()->user(), $data['reason']), 'رُفض')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListApprovalRequests::route('/')];
    }
}
