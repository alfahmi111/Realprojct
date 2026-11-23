<?php

namespace App\Filament\Platform\Resources\Subscribers;

use App\Filament\Support\Ui;
use App\Models\Subscriber;
use App\Plans\PlanEntitlements;
use App\Plans\SubscriberAdmin;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform admin: subscribers (REQ-0065, REQ-0323). Profile, subscription period, plan and price, add-ons, usage
 * against the plan, and account support for the office's users. The platform never sees the office's business
 * records — RLS keeps them outside any subscriber context (processor role, REQ-0453); only counts are shown.
 */
class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'مشترك';

    protected static ?string $pluralModelLabel = 'المشتركون';

    public const ADDONS_AR = ['qr_boards' => 'لوحات الباركود', 'asset_qr_labels' => 'ملصقات QR للأصول', 'waitlist' => 'قائمة الانتظار', 'extra_whatsapp_messages' => 'رسائل واتساب فوق الحد'];

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function planOptions(): array
    {
        return collect(PlanEntitlements::PLANS)->map(fn ($p) => $p['ar'] . ' — ' . ($p['max_units'] === null ? 'وحدات غير محدودة' : 'حتى ' . $p['max_units'] . ' وحدة'))->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات المكتب')->schema([
                TextInput::make('name_ar')->label('الاسم بالعربية')->required(),
                TextInput::make('name_en')->label('الاسم بالإنجليزية')->required(),
                TextInput::make('commercial_registration')->label('السجل التجاري')->maxLength(20),
                TextInput::make('vat_number')->label('الرقم الضريبي')->maxLength(20),
                TextInput::make('city')->label('المدينة')->maxLength(60),
                TextInput::make('contact_name')->label('اسم مسؤول التواصل'),
                TextInput::make('contact_phone')->label('جوال التواصل')->tel()->maxLength(30),
                TextInput::make('contact_email')->label('بريد التواصل')->email(),
            ])->columns(2),
            Section::make('الاشتراك')->schema([
                Select::make('plan')->label('الباقة')->options(self::planOptions())->required()
                    ->disabledOn('edit')->helperText(fn (string $operation) => $operation === 'edit' ? 'تُغيَّر من زر «تغيير الباقة» في صفحة المشترك، ليُتحقق من عدد الوحدات.' : null),
                Select::make('billing_cycle')->label('دورة الفوترة')->options(Subscriber::CYCLES),
                DatePicker::make('subscription_starts_on')->label('بداية الاشتراك')->native(true),
                DatePicker::make('subscription_ends_on')->label('نهاية الاشتراك')->native(true)->afterOrEqual('subscription_starts_on'),
                Ui::money('price_minor', 'السعر المتفق عليه لكل دورة')->helperText('الأسعار في الوثيقة «للنقاش» (§13)، فيُدخل السعر المتفق عليه مع كل مشترك.'),
                CheckboxList::make('addons')->label('الإضافات')->options(self::ADDONS_AR)->columns(2),
                Textarea::make('notes')->label('ملاحظات داخلية')->rows(2)->columnSpanFull(),
            ])->columns(2),
            Section::make('مدير المشترك الأول')->schema([
                TextInput::make('manager_name')->label('الاسم')->required(), TextInput::make('manager_email')->label('البريد')->email()->required(),
                TextInput::make('manager_password')->label('كلمة المرور المؤقتة')->password()->revealable()->rule(\Illuminate\Validation\Rules\Password::default())->required(),
            ])->columns(3)->visibleOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $admin = app(SubscriberAdmin::class);
        $today = CarbonImmutable::today();
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name_ar')->label('المشترك')->searchable()->description(fn (Subscriber $r) => $r->city),
                TextColumn::make('plan')->label('الباقة')->badge()->formatStateUsing(fn ($state) => PlanEntitlements::PLANS[$state]['ar'] ?? $state),
                TextColumn::make('state')->label('الحالة')->badge()
                    ->state(fn (Subscriber $r) => $admin->state($r, $today))
                    ->formatStateUsing(fn ($state) => SubscriberAdmin::STATE_AR[$state])
                    ->color(fn ($state) => SubscriberAdmin::STATE_COLOR[$state]),
                TextColumn::make('subscription_ends_on')->label('ينتهي في')->date('Y-m-d')->placeholder('—')->sortable()
                    ->description(function (Subscriber $r) use ($admin, $today) {
                        $d = $admin->daysRemaining($r, $today);
                        return $d === null ? null : ($d < 0 ? 'انتهى منذ ' . abs($d) . ' يوماً' : ($d === 0 ? 'ينتهي اليوم' : 'متبقٍ ' . $d . ' يوماً'));
                    }),
                TextColumn::make('units')->label('الوحدات')
                    ->state(function (Subscriber $r) use ($admin) {
                        $u = $admin->usage($r);
                        return $u['units'] . ' من ' . ($u['max_units'] ?? 'غير محدود');
                    }),
                TextColumn::make('created_at')->label('أُنشئ')->date('Y-m-d')->sortable(),
            ])
            ->filters([
                SelectFilter::make('plan')->label('الباقة')->options(collect(PlanEntitlements::PLANS)->map(fn ($p) => $p['ar'])->all()),
                Filter::make('suspended')->label('الموقوفون')->query(fn (Builder $query) => $query->where('status', 'suspended')),
                Filter::make('expiring')->label('ينتهي اشتراكهم قريباً')->query(fn (Builder $query) => $query->whereBetween('subscription_ends_on', [$today->toDateString(), $today->addDays((int) (app(\App\Settings\Settings::class)->get('subscription.warning_days') ?? 30))->toDateString()])),
                Filter::make('expired')->label('انتهى اشتراكهم')->query(fn (Builder $query) => $query->where('subscription_ends_on', '<', $today->toDateString())),
            ])
            ->recordUrl(fn (Subscriber $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([ViewAction::make()->label('فتح'), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscribers::route('/'),
            'create' => Pages\CreateSubscriber::route('/create'),
            'view' => Pages\ViewSubscriber::route('/{record}'),
            'edit' => Pages\EditSubscriber::route('/{record}/edit'),
        ];
    }
}
