<?php

namespace App\Filament\Manager\Resources\Owners\RelationManagers;

use App\Filament\Support\Ui;
use App\Models\ManagementAgreement;
use App\Models\Owner;
use App\Models\OwnershipShare;
use App\Models\Property;
use App\Properties\BuildingWizard;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Management agreements of an owner (REQ-0066, REQ-0067): covered properties, fee type and amounts, spending cap
 * without the owner's approval, who collects. The owner's statement needs one. An agreement is a contract: it is
 * never edited or deleted, only archived and replaced by a new one, so the statement history stays true.
 */
class AgreementsRelationManager extends RelationManager
{
    protected static string $relationship = 'agreements';

    protected static ?string $title = 'عقود الإدارة';

    protected static ?string $modelLabel = 'عقد إدارة';

    public const FEE_TYPES = ['percentage' => 'نسبة من الإيجار', 'fixed' => 'مبلغ ثابت', 'mixed' => 'نسبة ومبلغ ثابت'];

    public const COLLECTORS = ['manager' => 'المكتب يحصّل الإيجار', 'owner' => 'المالك يحصّل بنفسه'];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->hasPermission('properties.view');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('is_self')->label('المالك هو المدير نفسه (بلا أتعاب)')->live()->columnSpanFull(),
            Select::make('fee_type')->label('نوع الأتعاب')->options(self::FEE_TYPES)->default('percentage')->required()->live()
                ->hidden(fn (Get $get) => (bool) $get('is_self')),
            TextInput::make('fee_percentage')->label('نسبة الأتعاب')->suffix('%')->regex('/^\d{1,2}(\.\d{1,2})?$/')->required()
                ->helperText('من الإيجار المحصّل أو المستحق حسب إعدادات المكتب')
                ->visible(fn (Get $get) => ! $get('is_self') && in_array($get('fee_type'), ['percentage', 'mixed'], true)),
            Ui::money('fee_fixed_minor', 'المبلغ الثابت')->required()
                ->helperText('يُحتسب كل فترة حسب إعدادات المكتب (شهر، 3 أشهر، 6 أشهر، سنة)')
                ->visible(fn (Get $get) => ! $get('is_self') && in_array($get('fee_type'), ['fixed', 'mixed'], true)),
            Ui::money('spend_cap_minor', 'سقف الصرف دون موافقة المالك')->helperText('اتركه فارغاً إن لم يُتفق على سقف؛ كل صرف يحتاج موافقة المالك'),
            Select::make('collector')->label('من يحصّل الإيجار')->options(self::COLLECTORS)->default('manager')->required(),
            DatePicker::make('starts_on')->label('يبدأ في')->required()->native(true),
            DatePicker::make('ends_on')->label('ينتهي في')->native(true)->afterOrEqual('starts_on')->helperText('فارغ = مفتوح المدة'),
            CheckboxList::make('property_ids')->label('العقارات المشمولة')->required()->columnSpanFull()
                ->options(fn () => $this->ownerProperties())
                ->helperText('العقارات التي يملك فيها هذا المالك حصة'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('properties')->orderByDesc('starts_on'))
            ->emptyStateHeading('لا يوجد عقد إدارة')
            ->emptyStateDescription('أضف عقد الإدارة ليظهر كشف حساب المالك وتُحتسب الأتعاب.')
            ->columns([
                TextColumn::make('fee')->label('الأتعاب')->state(fn (ManagementAgreement $r) => self::feeText($r)),
                TextColumn::make('collector')->label('التحصيل')->formatStateUsing(fn ($state) => self::COLLECTORS[$state] ?? $state),
                Ui::moneyColumn('spend_cap_minor', 'سقف الصرف'),
                TextColumn::make('properties.name_ar')->label('العقارات')->badge(),
                TextColumn::make('starts_on')->label('من')->date('Y-m-d'),
                TextColumn::make('ends_on')->label('إلى')->date('Y-m-d')->placeholder('مفتوح'),
                TextColumn::make('archived_at')->label('الحالة')->state(fn (ManagementAgreement $r) => $r->archived_at ? 'مؤرشف' : 'ساري')->badge()
                    ->color(fn (string $state) => $state === 'ساري' ? 'success' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()->label('إضافة عقد إدارة')->modalHeading('عقد إدارة جديد')
                    ->using(fn (array $data) => $this->create($data)),
            ])
            ->recordActions([Ui::archive()]);
    }

    public static function feeText(ManagementAgreement $a): string
    {
        if ($a->is_self) {
            return 'بلا أتعاب (المالك هو المدير)';
        }
        $pct = intdiv($a->fee_percentage_bp, 100) . ($a->fee_percentage_bp % 100 ? '.' . str_pad((string) ($a->fee_percentage_bp % 100), 2, '0', STR_PAD_LEFT) : '') . '%';
        $fixed = \App\Support\Dates\NumberFormatter::money($a->fee_fixed_minor);
        return match ($a->fee_type) {
            'percentage' => $pct,
            'fixed' => $fixed,
            default => "$pct + $fixed",
        };
    }

    private function ownerProperties(): array
    {
        /** @var Owner $owner */
        $owner = $this->getOwnerRecord();
        $ids = OwnershipShare::where('owner_id', $owner->id)->pluck('property_id');
        return Property::whereIn('id', $ids)->whereNull('archived_at')->orderBy('name_ar')->pluck('name_ar', 'id')->all();
    }

    private function create(array $data): ManagementAgreement
    {
        $self = (bool) ($data['is_self'] ?? false);
        $type = $self ? 'fixed' : $data['fee_type'];
        $bp = 0;
        if (! $self && in_array($type, ['percentage', 'mixed'], true)) {
            [$whole, $frac] = array_pad(explode('.', (string) $data['fee_percentage']), 2, '0');
            $bp = (int) $whole * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
        }
        $terms = [
            'is_self' => $self, 'fee_type' => $type, 'fee_percentage_bp' => $bp,
            'fee_fixed_minor' => ! $self && in_array($type, ['fixed', 'mixed'], true) ? (int) $data['fee_fixed_minor'] : 0,
            'spend_cap_minor' => $data['spend_cap_minor'] ?? null, 'collector' => $data['collector'],
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null,
        ];
        $properties = Property::whereIn('id', array_keys($this->ownerProperties()))->whereIn('id', $data['property_ids'] ?? [])->get()->all();
        return app(BuildingWizard::class)->createAgreement($this->getOwnerRecord(), $properties, $terms);
    }
}
