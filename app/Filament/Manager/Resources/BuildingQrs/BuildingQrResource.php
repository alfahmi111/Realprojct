<?php

namespace App\Filament\Manager\Resources\BuildingQrs;

use App\BuildingQr\BuildingQrService;
use App\Filament\Support\Ui;
use App\Models\Building;
use App\Models\BuildingQr;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Building QR, free version (spec §8): permanent link, ad licence, pause, scans, waitlist length. */
class BuildingQrResource extends Resource
{
    protected static ?string $model = BuildingQr::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'باركود المبنى';

    protected static ?string $modelLabel = 'باركود مبنى';

    protected static ?string $pluralModelLabel = 'باركود المباني';

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
        $svc = fn () => app(BuildingQrService::class);
        return $table
            ->columns([
                TextColumn::make('building_id')->label('المبنى')->formatStateUsing(fn ($state) => Building::find($state)?->name_ar),
                TextColumn::make('code')->label('الرابط الثابت')->formatStateUsing(fn ($state) => url('/b/' . $state))->copyable(),
                TextColumn::make('ad_license_no')->label('ترخيص الإعلان'), TextColumn::make('ad_license_expires_on')->label('ينتهي')->date(),
                IconColumn::make('paused')->label('موقوف')->boolean(), TextColumn::make('scan_count')->label('المسحات'),
                TextColumn::make('waitlist')->label('قائمة الانتظار')->state(fn (BuildingQr $r) => array_sum($svc()->waitlistLength($r))),
            ])
            ->headerActions([
                Action::make('create')->label('باركود لمبنى')
                    ->schema([Select::make('building_id')->label('المبنى')->options(fn () => Building::whereNull('archived_at')->whereNotIn('id', BuildingQr::pluck('building_id'))->pluck('name_ar', 'id'))->required()])
                    ->action(fn (array $d) => Ui::attempt(fn () => $svc()->create(Building::findOrFail($d['building_id'])), 'أُنشئ الرابط الثابت')),
            ])
            ->recordActions([
                Action::make('license')->label('ترخيص الإعلان')
                    ->schema([TextInput::make('number')->label('رقم ترخيص الإعلان العقاري')->required(), DatePicker::make('expires')->label('ينتهي')->required()])
                    ->action(fn (BuildingQr $r, array $d) => Ui::attempt(fn () => $svc()->setAdLicense($r, $d['number'], CarbonImmutable::parse($d['expires'])))),
                Action::make('pause')->label(fn (BuildingQr $r) => $r->paused ? 'إعادة التشغيل' : 'إيقاف مؤقت')
                    ->action(fn (BuildingQr $r) => Ui::attempt(fn () => $svc()->pause($r, ! $r->paused))),
                Action::make('open')->label('فتح الصفحة')->url(fn (BuildingQr $r) => url('/b/' . $r->code), true),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBuildingQrs::route('/')];
    }
}
