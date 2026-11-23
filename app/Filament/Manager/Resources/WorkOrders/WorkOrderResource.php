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

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('maintenance.view');
    }
    protected static ?string $model = WorkOrder::class;

    protected static ?string $recordTitleAttribute = 'number';   // global search in the header

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|UnitEnum|null $navigationGroup = 'الصيانة';

    protected static ?string $modelLabel = 'أمر عمل';

    protected static ?string $pluralModelLabel = 'أوامر العمل';

    public const STATUS_AR = ['reported' => 'بلاغ', 'triaged' => 'تم الفرز', 'estimated' => 'قُدّرت التكلفة', 'awaiting_owner_approval' => 'بانتظار موافقة المالك', 'assigned' => 'مُسند', 'in_progress' => 'قيد التنفيذ', 'completed' => 'منجز', 'confirmed' => 'أكّده المستأجر', 'closed' => 'مغلق', 'rejected' => 'مرفوض'];

    public const TYPE_AR = ['corrective' => 'تصحيحية', 'emergency' => 'طارئة', 'unit_prep' => 'تجهيز وحدة', 'preventive' => 'وقائية', 'statutory' => 'نظامية'];

    public const PRIORITY_AR = ['emergency' => 'طارئ', 'urgent' => 'عاجل', 'normal' => 'عادي', 'scheduled' => 'مجدول'];

    /** Stages shown as tabs on the list: what needs sorting, what is being worked, what waits for the owner, what is done. */
    public const STAGES = [
        'triage' => ['label' => 'بانتظار الفرز', 'statuses' => ['reported']],
        'work' => ['label' => 'قيد العمل', 'statuses' => ['triaged', 'estimated', 'assigned', 'in_progress']],
        'owner' => ['label' => 'بانتظار المالك', 'statuses' => ['awaiting_owner_approval']],
        'done' => ['label' => 'منجزة', 'statuses' => ['completed', 'confirmed']],
        'closed' => ['label' => 'مغلقة', 'statuses' => ['closed', 'rejected']],
    ];

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
            Section::make('ما المشكلة؟')->description('صف المشكلة كما يراها من أبلغ عنها. الطارئ (انقطاع كهرباء، تسريب) يصل فوراً للمشرف.')->schema([
                \Filament\Forms\Components\ToggleButtons::make('type')->label('النوع')->options(['corrective' => 'تصحيحية', 'emergency' => 'طارئة', 'unit_prep' => 'تجهيز وحدة', 'preventive' => 'وقائية'])
                    ->icons(['corrective' => 'heroicon-o-wrench', 'emergency' => 'heroicon-o-bolt', 'unit_prep' => 'heroicon-o-sparkles', 'preventive' => 'heroicon-o-calendar'])
                    ->colors(['emergency' => 'danger'])->inline()->required()->default('corrective'),
                Textarea::make('description')->label('الوصف')->required()->rows(3)->placeholder('مثال: المكيف في الصالة لا يبرد منذ أمس'),
            ]),
            Section::make('أين؟')->schema([
                Select::make('property_id')->label('العقار')->options(fn () => Property::whereNull('archived_at')->orderBy('name_ar')->pluck('name_ar', 'id'))->required()->live(),
                Select::make('asset_id')->label('الأصل (اختياري)')->options(fn (\Filament\Schemas\Components\Utilities\Get $get) => Asset::whereNull('archived_at')->whereHas('space', fn ($q) => $q->where('property_id', $get('property_id')))->with('space')->get()->mapWithKeys(fn ($a) => [$a->id => $a->name_ar . ($a->space ? '، ' . $a->space->name_ar : '')]))->searchable()
                    ->helperText('إن كان الأصل تحت الضمان يُوجَّه البلاغ لجهة الضمان.'),
            ])->columns(2),
            Section::make('صور')->collapsible()->schema([
                FileUpload::make('photos_report')->label('صور المشكلة')->multiple()->image()->directory('work-orders')->maxFiles(6),
            ]),
        ])->columns(1);
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
        $ago = function ($at) {
            if (! $at) {
                return null;
            }
            $d = (int) \Carbon\CarbonImmutable::parse($at)->diffInDays(now());
            return $d === 0 ? 'اليوم' : ($d === 1 ? 'منذ يوم' : ($d === 2 ? 'منذ يومين' : 'منذ ' . $d . ($d <= 10 ? ' أيام' : ' يوماً')));
        };
        return $table->modifyQueryUsing(fn ($query) => $query->with(['property', 'space', 'asset', 'vendor', 'assignee', 'costLines'])->latest('reported_at'))
            ->columns([
                TextColumn::make('description')->label('البلاغ')->searchable(['description', 'number'])->weight('bold')->limit(60)->wrap()
                    ->description(fn (WorkOrder $r) => $r->number . ($r->asset ? '، ' . $r->asset->name_ar : '')),
                TextColumn::make('property.name_ar')->label('أين')->description(fn (WorkOrder $r) => $r->space?->name_ar),
                TextColumn::make('type')->label('النوع')->badge()->color('gray')->formatStateUsing(fn ($state) => self::TYPE_AR[$state] ?? $state)
                    ->description(fn (WorkOrder $r) => $r->priority ? null : 'لم تُحدَّد أولويته'),
                TextColumn::make('priority')->label('الأولوية')->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state) => self::PRIORITY_AR[$state] ?? $state)
                    ->color(fn ($state) => ['emergency' => 'danger', 'urgent' => 'warning', 'normal' => 'info', 'scheduled' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('status')->label('المرحلة')->formatStateUsing(fn ($state) => self::STATUS_AR[$state] ?? $state)->badge()
                    ->color(fn ($state) => match ($state) { 'reported' => 'danger', 'awaiting_owner_approval' => 'warning', 'completed', 'confirmed', 'closed' => 'success', 'rejected' => 'gray', default => 'info' }),
                TextColumn::make('who')->label('المنفّذ')->state(fn (WorkOrder $r) => $r->vendor?->name_ar ?? $r->assignee?->name)->placeholder('لم يُسند'),
                TextColumn::make('cost')->label('التكلفة')->state(fn (WorkOrder $r) => $r->costLines->sum(fn ($c) => $c->total_minor->minor()) ?: ($r->estimated_cost_minor?->minor()))
                    ->formatStateUsing(fn ($state) => $state ? \App\Support\Dates\NumberFormatter::money((int) $state) : '—')
                    ->description(fn (WorkOrder $r) => $r->costLines->isNotEmpty() ? 'فعلية' : ($r->estimated_cost_minor ? 'تقديرية' : null)),
                TextColumn::make('reported_at')->label('العمر')->sortable()->formatStateUsing(fn ($state) => $ago($state))
                    ->description(fn (WorkOrder $r) => $r->reported_at?->format('Y-m-d')),
            ])
            ->filters([
                SelectFilter::make('status')->label('المرحلة')->options(self::STATUS_AR),
                SelectFilter::make('priority')->label('الأولوية')->options(self::PRIORITY_AR),
                SelectFilter::make('property_id')->label('العقار')->relationship('property', 'name_ar'),
            ])
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
