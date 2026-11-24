<?php

namespace App\Filament\Manager\Resources\Tenants;

use App\Filament\Support\Ui;
use App\Models\Tenant;
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

/** Generated screen: المستأجرون (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class TenantResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('leasing.view');
    }

    /** Write gate (review M18): viewing roles do not create. */
    public static function canCreate(): bool
    {
        return \App\Filament\Support\Ui::allowed('leasing.edit');
    }

    /** Write gate (review M18): viewing roles do not edit. */
    public static function canEdit(Model $record): bool
    {
        return \App\Filament\Support\Ui::allowed('leasing.edit');
    }
    protected static ?string $model = Tenant::class;

    protected static ?string $recordTitleAttribute = 'name_ar';   // global search in the header

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $modelLabel = 'المستأجر';

    protected static ?string $pluralModelLabel = 'المستأجرون';

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
        $company = fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('kind') === 'company';
        return $schema->components([
            Section::make('من المستأجر؟')->description('فرد بهويته أو منشأة بسجلها التجاري. الاسم كما في الهوية أو السجل، فهو يظهر في العقد والفواتير.')->schema([
                \Filament\Forms\Components\ToggleButtons::make('kind')->label('النوع')->options(['individual' => 'فرد', 'company' => 'منشأة'])->icons(['individual' => 'heroicon-o-user', 'company' => 'heroicon-o-building-office'])->inline()->required()->live()->default('individual'),
                TextInput::make('name_ar')->label('الاسم بالعربية')->required(),
                TextInput::make('name_en')->label('الاسم بالإنجليزية')->helperText('اختياري؛ للمستأجر غير الناطق بالعربية.'),
                TextInput::make('national_id')->label('رقم الهوية أو الإقامة')->password()->revealable()->hidden($company)->regex('/^[12]\d{9}$/')
                    ->validationMessages(['regex' => 'رقم الهوية أو الإقامة 10 أرقام يبدأ بـ 1 أو 2.'])->helperText('يُخزَّن مشفّراً ولا يظهر إلا لمن له الصلاحية.'),
                TextInput::make('commercial_registration')->label('السجل التجاري')->visible($company)->maxLength(20),
                TextInput::make('vat_number')->label('الرقم الضريبي')->visible($company)->regex('/^3\d{13}3$/')
                    ->validationMessages(['regex' => 'الرقم الضريبي 15 رقماً يبدأ بـ 3 وينتهي بـ 3.'])->helperText('إن كانت المنشأة مسجّلة؛ يظهر في فواتيرها.'),
            ])->columns(2),
            Section::make('التواصل')->description('تصله التذكيرات والفواتير على واتساب بلغته المختارة.')->schema([
                TextInput::make('phone')->label('الجوال')->tel()->placeholder('9665XXXXXXXX')->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('email')->label('البريد الإلكتروني')->email()->extraInputAttributes(['dir' => 'ltr']),
                Select::make('messages_locale')->label('لغة الرسائل')->options(['ar' => 'العربية', 'en' => 'English'])->default('ar'),
            ])->columns(3),
            Section::make('العنوان الوطني')->description('إلزامي في الفواتير الضريبية للمنشآت، ومفيد في العقد.')->collapsible()->schema([
                TextInput::make('national_address.building_no')->label('رقم المبنى')->regex('/^\\d{4}$/'), TextInput::make('national_address.street')->label('الشارع'),
                TextInput::make('national_address.district')->label('الحي'), TextInput::make('national_address.city')->label('المدينة'),
                TextInput::make('national_address.postal_code')->label('الرمز البريدي')->regex('/^\\d{5}$/'), TextInput::make('national_address.additional_no')->label('الرقم الإضافي')->regex('/^\\d{4}$/'),
            ])->columns(3),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $p = fn (Tenant $t) => app(\App\Dashboards\TenantProfiles::class)->all()[$t->id] ?? [];
        $base = fn () => filament()->getCurrentPanel()->getUrl();
        $wa = fn (?string $phone) => $phone ? 'https://wa.me/' . preg_replace('/\D/', '', $phone) : null;
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at')->orderBy('name_ar'))
            ->columns([
                TextColumn::make('name_ar')->label('المستأجر')->searchable(['name_ar', 'phone', 'national_id', 'commercial_registration'])->weight('bold')
                    ->description(fn (Tenant $t) => implode(' · ', array_filter([$t->kind === 'company' ? 'منشأة' . ($t->commercial_registration ? '، سجل ' . $t->commercial_registration : '') : 'فرد', \App\Filament\Support\IdentityActions::badge($t)]))),
                TextColumn::make('unit')->label('يسكن الآن')->state(fn (Tenant $t) => ($x = $p($t)) && $x['unit'] ? 'الوحدة ' . $x['unit'] : null)
                    ->description(fn (Tenant $t) => $p($t)['property'] ?? null)->placeholder('بلا عقد ساري')
                    ->url(fn (Tenant $t) => ($id = $p($t)['lease_id'] ?? null) ? $base() . '/leases/' . $id : null),
                TextColumn::make('ends')->label('ينتهي العقد')->state(fn (Tenant $t) => $p($t)['days_left'] ?? null)->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state < 0 ? 'انتهى' : 'بعد ' . $state . ' يوماً')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state <= 30 ? 'danger' : ($state <= 90 ? 'warning' : 'gray')))
                    ->description(fn (Tenant $t) => ($p($t)['ends_on'] ?? null)?->format('Y-m-d')),
                TextColumn::make('outstanding')->label('المستحق عليه')->state(fn (Tenant $t) => $p($t)['outstanding'] ?? 0)
                    ->formatStateUsing(fn ($state) => (int) $state > 0 ? \App\Support\Dates\NumberFormatter::money((int) $state) : 'لا شيء')
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : 'success')
                    ->description(fn (Tenant $t) => ($n = $p($t)['overdue'] ?? 0) ? ($n === 1 ? 'فاتورة متأخرة' : $n . ' فواتير متأخرة') : null),
                TextColumn::make('history')->label('عقوده')->state(fn (Tenant $t) => $p($t)['leases'] ?? 0)->alignCenter()->toggleable(),
                TextColumn::make('phone')->label('التواصل')->placeholder('لا جوال')->icon(Heroicon::OutlinedPhone)
                    ->url(fn (Tenant $t) => $t->phone ? 'tel:+' . preg_replace('/\D/', '', $t->phone) : null)->extraAttributes(['dir' => 'ltr'])
                    ->description(fn (Tenant $t) => $t->email),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('state')->label('الحال')
                    ->options(['active' => 'لهم عقد ساري', 'none' => 'بلا عقد ساري', 'arrears' => 'عليهم مستحقات', 'ending' => 'تنتهي عقودهم خلال 90 يوماً'])
                    ->query(function ($query, array $data) {
                        $all = collect(app(\App\Dashboards\TenantProfiles::class)->all());
                        $ids = match ($data['value'] ?? null) {
                            'active' => $all->whereNotNull('lease_id')->keys(),
                            'none' => $all->whereNull('lease_id')->keys(),
                            'arrears' => $all->where('outstanding', '>', 0)->keys(),
                            'ending' => $all->filter(fn ($x) => $x['days_left'] !== null && $x['days_left'] <= 90)->keys(),
                            default => null,
                        };
                        return $ids === null ? $query : $query->whereIn('id', $ids->all() ?: [0]);
                    }),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('whatsapp')->label('واتساب')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('success')
                    ->visible(fn (Tenant $t) => (bool) $t->phone)->url(fn (Tenant $t) => $wa($t->phone), shouldOpenInNewTab: true),
                \App\Filament\Support\IdentityActions::group(),
                EditAction::make(), \App\Filament\Support\Ui::archive('leasing.edit'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }

    /** The user's property scope (REQ-0494, review M18 A2). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Filament\Support\PropertyScope::apply(parent::getEloquentQuery(), 'tenant');
    }
}
