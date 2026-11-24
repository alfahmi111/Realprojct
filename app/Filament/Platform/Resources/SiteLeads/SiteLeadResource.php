<?php

namespace App\Filament\Platform\Resources\SiteLeads;

use App\Models\SiteLead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Platform admin: demo requests from the public website (M19). Contacted or archived, never deleted. */
class SiteLeadResource extends Resource
{
    protected static ?string $model = SiteLead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $modelLabel = 'طلب عرض';

    protected static ?string $pluralModelLabel = 'طلبات العرض من الموقع';

    public const STATUS_AR = ['new' => 'جديد', 'contacted' => 'تم التواصل', 'archived' => 'مؤرشف'];

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
        $n = SiteLead::where('status', 'new')->count();
        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->weight('bold')->description(fn (SiteLead $r) => $r->organization),
                TextColumn::make('phone')->label('الجوال')->searchable()->copyable(),
                TextColumn::make('city')->label('المدينة')->placeholder('—'),
                TextColumn::make('units')->label('الوحدات')->formatStateUsing(fn ($state) => \App\Http\Controllers\SiteController::UNITS[$state] ?? $state)->placeholder('—'),
                TextColumn::make('message')->label('الرسالة')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('وصل')->since(),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::STATUS_AR[$state] ?? $state)
                    ->color(fn ($state) => ['new' => 'warning', 'contacted' => 'success'][$state] ?? 'gray'),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(self::STATUS_AR)])
            ->recordActions([
                Action::make('contacted')->label('تم التواصل')->icon('heroicon-o-phone')->visible(fn (SiteLead $r) => $r->status === 'new')
                    ->action(fn (SiteLead $r) => $r->update(['status' => 'contacted'])),
                Action::make('archive')->label('أرشفة')->color('gray')->visible(fn (SiteLead $r) => $r->status !== 'archived')
                    ->action(fn (SiteLead $r) => $r->update(['status' => 'archived'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSiteLeads::route('/')];
    }
}
