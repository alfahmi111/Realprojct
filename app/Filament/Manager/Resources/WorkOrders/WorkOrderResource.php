<?php

namespace App\Filament\Manager\Resources\WorkOrders;

use App\Filament\Support\Ui;
use App\Maintenance\MaintenanceService;
use App\Models\Asset;
use App\Models\Property;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Support\Money\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

/** Work orders through the lifecycle of spec §6.3 (REQ-0227…REQ-0230, REQ-0318: follow and assign). */
class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|UnitEnum|null $navigationGroup = 'الصيانة';

    protected static ?string $modelLabel = 'أمر عمل';

    protected static ?string $pluralModelLabel = 'أوامر العمل';

    public const STATUS_AR = ['reported' => 'بلاغ', 'triaged' => 'تم الفرز', 'estimated' => 'قُدّرت التكلفة', 'awaiting_owner_approval' => 'بانتظار موافقة المالك', 'assigned' => 'مُسند', 'in_progress' => 'قيد التنفيذ', 'completed' => 'منجز', 'confirmed' => 'أكّده المستأجر', 'closed' => 'مغلق', 'rejected' => 'مرفوض'];

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
            Select::make('type')->label('النوع')->options(['corrective' => 'تصحيحية', 'emergency' => 'طارئة', 'unit_prep' => 'تجهيز وحدة', 'preventive' => 'وقائية'])->required(),
            Select::make('property_id')->label('العقار')->options(fn () => Property::whereNull('archived_at')->pluck('name_ar', 'id'))->required()->live(),
            Select::make('asset_id')->label('الأصل')->options(fn (\Filament\Schemas\Components\Utilities\Get $get) => Asset::whereNull('archived_at')->whereHas('space', fn ($q) => $q->where('property_id', $get('property_id')))->pluck('name_ar', 'id'))->searchable(),
            Textarea::make('description')->label('الوصف')->required()->columnSpanFull(),
            FileUpload::make('photos_report')->label('الصور')->multiple()->image()->directory('work-orders')->columnSpanFull(),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('أمر العمل')->schema([
                TextEntry::make('number')->label('الرقم'), TextEntry::make('type')->label('النوع'), TextEntry::make('priority')->label('الأولوية'),
                TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::STATUS_AR[$state] ?? $state)->badge(),
                TextEntry::make('description')->label('الوصف')->columnSpanFull(), TextEntry::make('tenant_rating')->label('تقييم المستأجر'),
            ])->columns(4),
            Section::make('التكاليف')->schema([
                RepeatableEntry::make('costLines')->label('')->schema([
                    TextEntry::make('kind')->label('النوع'), TextEntry::make('description_ar')->label('البند'), TextEntry::make('quantity')->label('الكمية'),
                    TextEntry::make('total_minor')->label('الإجمالي')->formatStateUsing(fn ($s) => $s->toDecimalString()),
                ])->columns(4),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $svc = fn () => app(MaintenanceService::class);
        return $table->modifyQueryUsing(fn ($query) => $query->latest('reported_at'))
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(), TextColumn::make('type')->label('النوع'), TextColumn::make('priority')->label('الأولوية')->badge(),
                TextColumn::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::STATUS_AR[$state] ?? $state)->badge(),
                TextColumn::make('reported_at')->label('البلاغ')->dateTime()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(self::STATUS_AR)])
            ->recordActions([
                ViewAction::make(),
                Action::make('triage')->label('فرز')->visible(fn (WorkOrder $r) => $r->status === 'reported')
                    ->schema([Select::make('priority')->label('الأولوية')->options(['emergency' => 'طارئ', 'urgent' => 'عاجل', 'normal' => 'عادي', 'scheduled' => 'مجدول'])->required()])
                    ->action(fn (WorkOrder $r, array $data) => Ui::attempt(fn () => $svc()->triage($r, $data['priority']))),
                Action::make('estimate')->label('تقدير التكلفة')->visible(fn (WorkOrder $r) => $r->status === 'triaged')
                    ->schema([Ui::money('cost', 'التكلفة التقديرية')->required()])
                    ->action(fn (WorkOrder $r, array $data) => Ui::attempt(fn () => $svc()->estimate($r, Money::of((int) $data['cost'])))),
                Action::make('assign')->label('إسناد')->visible(fn (WorkOrder $r) => $r->status === 'estimated')
                    ->schema([
                        Select::make('technician')->label('فني داخلي')->options(fn () => User::where('role', 'technician')->whereNull('archived_at')->pluck('name', 'id')),
                        Select::make('vendor')->label('أو مورّد')->options(fn () => Vendor::whereNull('archived_at')->pluck('name_ar', 'id')),
                    ])
                    ->action(fn (WorkOrder $r, array $data) => Ui::attempt(fn () => $svc()->assign($r, isset($data['technician']) ? User::find($data['technician']) : null, isset($data['vendor']) ? Vendor::find($data['vendor']) : null), 'أُسند')),
                Action::make('cost')->label('إضافة تكلفة')->visible(fn (WorkOrder $r) => in_array($r->status, ['assigned', 'in_progress', 'completed'], true))
                    ->schema([Select::make('kind')->label('النوع')->options(['labour' => 'عمالة', 'part' => 'قطع', 'vendor' => 'مورّد'])->required(), TextInput::make('description')->label('البند')->required(), TextInput::make('quantity')->label('الكمية')->integer()->minValue(1)->default(1)->required(), Ui::money('unit_cost', 'سعر الوحدة')->required()])
                    ->action(fn (WorkOrder $r, array $data) => Ui::attempt(fn () => $svc()->addCost($r, $data['kind'], $data['description'], (int) $data['quantity'], Money::of((int) $data['unit_cost'])))),
                Action::make('close')->label('إغلاق وتحويل التكلفة')->visible(fn (WorkOrder $r) => in_array($r->status, ['confirmed', 'completed'], true))
                    ->schema([FileUpload::make('tenant_caused')->label('موافقة موثّقة لتحميل التكلفة على المستأجر (اختياري)')->directory('work-orders/approvals')])
                    ->action(fn (WorkOrder $r, array $data) => Ui::attempt(fn () => $svc()->close($r, auth()->user(), $data['tenant_caused'] ?? null), 'أُغلق؛ الصرف بانتظار المراجِع')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWorkOrders::route('/'), 'create' => Pages\CreateWorkOrder::route('/create'), 'view' => Pages\ViewWorkOrder::route('/{record}')];
    }
}
