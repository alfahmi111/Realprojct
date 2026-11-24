<?php

namespace App\Filament\Manager\Resources\Owners;

use App\Filament\Support\Ui;
use App\Models\Owner;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Generated screen: الملاك (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class OwnerResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('properties.view');
    }

    /** Write gate (review M18): viewing roles do not create. */
    public static function canCreate(): bool
    {
        return \App\Filament\Support\Ui::allowed('properties.edit');
    }

    /** Write gate (review M18): viewing roles do not edit. */
    public static function canEdit(Model $record): bool
    {
        return \App\Filament\Support\Ui::allowed('properties.edit');
    }
    protected static ?string $model = Owner::class;

    protected static ?string $recordTitleAttribute = 'name_ar';   // global search in the header

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'العقارات';

    protected static ?string $modelLabel = 'المالك';

    protected static ?string $pluralModelLabel = 'الملاك';

    public const KIND_AR = ['individual' => 'فرد', 'heirs' => 'ورثة', 'company' => 'شركة'];

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('من المالك؟')->description('فرد، أو ورثة يمثّلهم وكيل، أو شركة. الاسم يظهر في كشف الحساب والفواتير.')->schema([
                \Filament\Forms\Components\ToggleButtons::make('kind')->label('النوع')->options(self::KIND_AR)->icons(['individual' => 'heroicon-o-user', 'heirs' => 'heroicon-o-user-group', 'company' => 'heroicon-o-building-office'])->inline()->required()->live()->default('individual'),
                TextInput::make('name_ar')->label('الاسم بالعربية')->required(),
                TextInput::make('name_en')->label('الاسم بالإنجليزية'),
                TextInput::make('national_id')->label('رقم الهوية')->password()->revealable()->dehydrated(fn ($state) => filled($state))->placeholder(fn (?\App\Models\Owner $record) => $record?->getRawOriginal('national_id') ? 'محفوظ — اكتب رقماً جديداً لتغييره' : null)->hidden(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('kind') === 'company')->helperText('يُخزَّن مشفّراً.'),
                TextInput::make('commercial_registration')->label('السجل التجاري')->dehydrated(fn ($state) => filled($state))->placeholder(fn (?\App\Models\Owner $record) => $record?->getRawOriginal('commercial_registration') ? 'محفوظ — اكتب رقماً جديداً لتغييره' : null)->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('kind') === 'company'),
                Toggle::make('resident')->label('مقيم في المملكة')->default(true)->helperText('غير المقيم قد تختلف معاملته الضريبية.'),
            ])->columns(2),
            Section::make('الضريبة')->description('إن كان المالك مسجّلاً في ضريبة القيمة المضافة، تحمل فواتير الإيجار التجاري ضريبة 15% باسمه.')->schema([
                Toggle::make('vat_registered')->label('مسجّل في ضريبة القيمة المضافة')->live(),
                TextInput::make('vat_number')->label('الرقم الضريبي')->regex('/^3\d{13}3$/')->validationMessages(['regex' => 'الرقم الضريبي 15 رقماً يبدأ بـ 3 وينتهي بـ 3.'])
                    ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (bool) $get('vat_registered'))->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => (bool) $get('vat_registered')),
            ])->columns(2),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $money = fn ($v) => \App\Support\Dates\NumberFormatter::money($v);
        $agreement = fn (\App\Models\Owner $o) => $o->agreements->filter(fn ($a) => $a->starts_on <= now() && ($a->ends_on === null || $a->ends_on >= now()))->sortByDesc('starts_on')->first();
        $net = function (\App\Models\Owner $o) {
            static $cache = [];
            return $cache[$o->id] ??= rescue(fn () => app(\App\Finance\TrustAccounting::class)->statement($o, \Carbon\CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth())['net_to_owner'], null, false);
        };
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at')->with(['shares.property', 'agreements'])->orderBy('name_ar'))
            ->columns([
                TextColumn::make('name_ar')->label('المالك')->searchable(['name_ar', 'national_id', 'commercial_registration'])->weight('bold')
                    ->description(fn (\App\Models\Owner $o) => implode(' · ', array_filter([self::KIND_AR[$o->kind] ?? $o->kind, \App\Filament\Support\IdentityActions::badge($o)]))),
                TextColumn::make('properties')->label('عقاراته وحصصه')->wrap()->placeholder('لم يُربط بعقار')
                    ->state(fn (\App\Models\Owner $o) => $o->shares->map(fn ($s) => $s->property?->name_ar . ($s->share_numerator == $s->share_denominator ? '' : ' (' . $s->share_numerator . '/' . $s->share_denominator . ')'))->filter()->implode('، ') ?: null),
                TextColumn::make('fee')->label('أتعاب الإدارة')->placeholder('لا عقد إدارة ساري')
                    ->state(fn (\App\Models\Owner $o) => ($a = $agreement($o)) ? RelationManagers\AgreementsRelationManager::feeText($a) : null)
                    ->description(fn (\App\Models\Owner $o) => ($a = $agreement($o)) ? ($a->collector === 'manager' ? 'المكتب يحصّل (أمانات)' : 'في حساب المالك') : null),
                TextColumn::make('cap')->label('موافقته على الصرف')->placeholder('—')
                    ->state(fn (\App\Models\Owner $o) => ($a = $agreement($o)) ? RelationManagers\AgreementsRelationManager::ruleText($a->spend_cap_minor) : null),
                TextColumn::make('net')->label('صافي الشهر الماضي')->placeholder('—')
                    ->state(fn (\App\Models\Owner $o) => ($n = $net($o)) ? $money($n) : null)
                    ->url(fn () => \App\Filament\Manager\Pages\OwnerStatementPage::getUrl()),
                IconColumn::make('vat_registered')->label('مسجّل ضريبياً')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([\Filament\Tables\Filters\SelectFilter::make('kind')->label('النوع')->options(self::KIND_AR)])
            ->recordActions([\App\Filament\Support\IdentityActions::group(), EditAction::make(), \App\Filament\Support\Ui::archive('properties.edit'),]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\AgreementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOwners::route('/'),
            'create' => Pages\CreateOwner::route('/create'),
            'edit' => Pages\EditOwner::route('/{record}/edit'),
        ];
    }

    /** The user's property scope (REQ-0494, review M18 A2). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Filament\Support\PropertyScope::apply(parent::getEloquentQuery(), 'owner');
    }
}
