<?php

namespace App\Filament\Manager\Resources\Announcements;

use App\Filament\Support\Ui;
use App\Models\Announcement;
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

/** Generated screen: إعلانات للمستأجرين (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $modelLabel = 'الإعلان';

    protected static ?string $pluralModelLabel = 'إعلانات للمستأجرين';

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
                Select::make('building_id')->label('المبنى (فارغ = الجميع)')->options(fn () => \App\Models\Building::pluck('name_ar', 'id')),
                TextInput::make('title_ar')->label('العنوان')->required(),
                Textarea::make('body_ar')->label('النص')->required(),
                DateTimePicker::make('published_at')->label('النشر')->default(now())->required(),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title_ar')->label('العنوان'),
                TextColumn::make('published_at')->label('النشر')->dateTime(),
            ])
            ->recordActions([EditAction::make(), ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
