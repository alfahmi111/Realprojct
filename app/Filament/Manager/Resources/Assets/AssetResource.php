<?php

namespace App\Filament\Manager\Resources\Assets;

use App\Filament\Support\Ui;
use App\Models\Asset;
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

/** Generated screen: الأصول (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class AssetResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('assets.view');
    }
    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'الصيانة';

    protected static ?string $modelLabel = 'الأصل';

    protected static ?string $pluralModelLabel = 'الأصول';

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
                Select::make('space_id')->label('الموقع في الشجرة')->options(fn () => \App\Models\Space::whereNull('archived_at')->orderBy('code')->get()->mapWithKeys(fn ($s) => [$s->id => $s->code . ' — ' . $s->name_ar]))->searchable()->required(),
                Select::make('category')->label('الفئة')->options(collect(\App\Maintenance\AssetTaxonomy::CATEGORIES)->map(fn ($c) => $c['ar']))->live()->required(),
                Select::make('type')->label('النوع')->options(fn (\Filament\Schemas\Components\Utilities\Get $get) => \App\Maintenance\AssetTaxonomy::CATEGORIES[$get('category')]['types'] ?? [])->required(),
                TextInput::make('qr_code')->label('رمز QR الداخلي')->required()->default(fn () => 'A-' . strtoupper(\Illuminate\Support\Str::random(10))),
                TextInput::make('name_ar')->label('الاسم')->required(),
                TextInput::make('brand')->label('الماركة'),
                TextInput::make('model')->label('الموديل'),
                TextInput::make('serial_number')->label('الرقم التسلسلي'),
                DatePicker::make('installed_on')->label('تاريخ التركيب'),
                DatePicker::make('warranty_ends_on')->label('انتهاء الضمان'),
                Select::make('condition')->label('الحالة (1–5)')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
                Section::make('القيمة والإهلاك')->description('قسط ثابت: يفقد الأصل كل سنة القدر نفسه من قيمته حتى يخرج من الخدمة.')->schema([
                    Ui::money('cost_minor', 'التكلفة')->live(onBlur: true),
                    TextInput::make('useful_life_months')->label('العمر الافتراضي (شهر)')->integer()->minValue(1)
                        ->helperText('يُستعمل لحساب الإهلاك إن لم تحدد نسبة أو مبلغاً'),
                    Select::make('depreciation_method')->label('طريقة الإهلاك')->live()
                        ->options(['percent' => 'نسبة سنوية من التكلفة', 'amount' => 'مبلغ سنوي ثابت'])->placeholder('حسب العمر الافتراضي'),
                    TextInput::make('depreciation_rate_pct')->label('النسبة السنوية')->suffix('%')->numeric()->minValue(0.01)->maxValue(100)
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('depreciation_method') === 'percent')->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('depreciation_method') === 'percent')
                        ->afterStateHydrated(fn ($component, $record) => $component->state($record?->depreciation_rate_bp ? $record->depreciation_rate_bp / 100 : null))
                        ->dehydrated(false),
                    Ui::money('depreciation_annual_minor', 'المبلغ السنوي')
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('depreciation_method') === 'amount')->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('depreciation_method') === 'amount'),
                    Ui::money('salvage_minor', 'قيمة الخردة (اختياري)')->helperText('ما يساويه الأصل عند خروجه من الخدمة؛ إن تُرك فارغاً فهو صفر'),
                ])->columns(2)->columnSpanFull(),
            ])->columns(2);
    }

    /** Converts the percentage typed on the form into basis points and clears the unused basis (form ↔ ر-02 integers). */
    public static function prepareDepreciation(array $data, array $raw): array
    {
        $method = $data['depreciation_method'] ?? null;
        $pct = $raw['depreciation_rate_pct'] ?? null;
        $data['depreciation_rate_bp'] = $method === 'percent' && $pct !== null && $pct !== '' ? (int) round(((float) $pct) * 100) : null;
        if ($method !== 'amount') {
            $data['depreciation_annual_minor'] = null;
        }
        return $data;
    }

    public static function table(Table $table): Table
    {
        $val = app(\App\Maintenance\AssetValuation::class);
        $today = \Carbon\CarbonImmutable::today();
        return $table->modifyQueryUsing(fn ($query) => $query->whereNull('archived_at')->with('space'))
            ->columns([
                TextColumn::make('qr_code')->label('الرمز')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name_ar')->label('الأصل')->searchable()->description(fn (Asset $r) => trim(($r->brand ?? '') . ' ' . ($r->model ?? ''))),
                TextColumn::make('space.name_ar')->label('الموقع'),
                TextColumn::make('category')->label('الفئة')->formatStateUsing(fn ($state) => \App\Maintenance\AssetTaxonomy::CATEGORIES[$state]['ar'] ?? $state),
                TextColumn::make('condition')->label('الحالة')->formatStateUsing(fn ($state) => $state ? $state . ' من 5' : '—'),
                TextColumn::make('book')->label('القيمة الحالية')->state(fn (Asset $r) => $val->value($r, $today)['book'])
                    ->formatStateUsing(fn ($state) => \App\Support\Dates\NumberFormatter::money((int) $state))->placeholder('ناقص البيانات'),
                TextColumn::make('pct')->label('الإهلاك')->state(fn (Asset $r) => $val->value($r, $today)['pct_bp'])
                    ->formatStateUsing(fn ($state) => intdiv((int) $state, 100) . '%')->placeholder('—'),
                TextColumn::make('ends')->label('يخرج من الخدمة')->state(fn (Asset $r) => $val->value($r, $today)['ends_on']?->format('Y-m'))
                    ->badge()->color(fn (Asset $r) => match ($val->value($r, $today)['state']) { 'ended' => 'danger', 'near' => 'warning', default => 'gray' })->placeholder('—'),
                TextColumn::make('warranty_ends_on')->label('انتهاء الضمان')->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([\Filament\Tables\Filters\SelectFilter::make('category')->label('الفئة')->options(collect(\App\Maintenance\AssetTaxonomy::CATEGORIES)->map(fn ($c) => $c['ar']))])
            ->recordActions([EditAction::make(), \App\Filament\Support\Ui::archive('assets.edit'),]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'edit' => Pages\EditAsset::route('/{record}/edit'),
        ];
    }

    /** The user's property scope (REQ-0494, review M18 A2). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Filament\Support\PropertyScope::apply(parent::getEloquentQuery(), 'space');
    }
}
