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

/** Generated screen: تعاميم للمستأجرين (manager interface). Records are never deleted (REQ-0168); RLS isolates the subscriber. */
class AnnouncementResource extends Resource
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('leasing.announcements');
    }
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'التأجير';

    protected static ?string $modelLabel = 'تعميم';

    protected static ?string $pluralModelLabel = 'تعاميم للمستأجرين';

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
            \Filament\Schemas\Components\Section::make('التعميم')->description('ما يقرؤه المستأجر في واجهته: تنبيه، أو شرح، أو تهنئة. أرفق صوراً أو فيديو أو ملف PDF إن احتجت.')->schema([
                TextInput::make('title_ar')->label('العنوان')->required()->maxLength(120)->placeholder('مثال: انقطاع المياه يوم الخميس'),
                Textarea::make('body_ar')->label('النص')->required()->rows(5),
                \Filament\Forms\Components\FileUpload::make('attachments')->label('الوسائط (اختياري)')->multiple()->maxFiles(6)->maxSize(20480)->reorderable()
                    ->disk('local')->visibility('private')->directory(fn () => 'announcements/' . (auth()->user()?->subscriber_id ?? 'x'))
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'application/pdf'])
                    ->helperText('صور JPG أو PNG، أو فيديو MP4، أو PDF. حتى 6 ملفات، 20 ميغابايت لكل ملف.'),
            ])->columnSpan(2),
            \Filament\Schemas\Components\Section::make('لمن')->description('اترك المباني فارغة ليصل التعميم لكل المباني.')->schema([
                Select::make('building_ids')->label('المباني')->multiple()->live()->placeholder('كل المباني')
                    ->options(fn () => \App\Models\Building::whereNull('archived_at')->with('property')->get()->mapWithKeys(fn ($b) => [$b->id => $b->property->name_ar . ' — ' . $b->name_ar])),
                Select::make('excluded_unit_ids')->label('استثناء وحدات')->multiple()->searchable()
                    ->options(fn (\Filament\Schemas\Components\Utilities\Get $g) => self::unitOptions((array) $g('building_ids')))
                    ->helperText('لن يرى مستأجرو هذه الوحدات التعميم.'),
            ])->columnSpan(1),
            \Filament\Schemas\Components\Section::make('متى')->schema([
                DateTimePicker::make('published_at')->label('يبدأ عرضه')->default(now())->required()->seconds(false),
                DateTimePicker::make('expires_at')->label('ينتهي عرضه (اختياري)')->seconds(false)->after('published_at')
                    ->helperText('بعده يختفي من واجهة المستأجر ويبقى في السجل.'),
            ])->columnSpan(1),
        ])->columns(4);
    }

    /** Leasable units of the chosen buildings (all buildings when none chosen), labelled with their building. */
    public static function unitOptions(array $buildingIds): array
    {
        $spaces = \App\Models\Space::where('kind', 'unit')->whereNull('archived_at')->when($buildingIds, fn ($q) => $q->whereIn('building_id', $buildingIds))->with('building')->get()->keyBy('id');
        return \App\Models\LeasableUnit::whereNull('archived_at')->whereHas('spaces', fn ($q) => $q->whereIn('spaces.id', $spaces->keys()))->with('spaces')->orderBy('code')->get()
            ->mapWithKeys(fn ($u) => [$u->id => $u->code . ' — ' . ($u->spaces->first()?->building?->name_ar ?? '')])->all();
    }

    /** "كل المباني" or "عمارة النخيل، برج الندى" and "باستثناء وحدتين". */
    public static function audience(Announcement $a): string
    {
        $ids = (array) $a->building_ids;
        $names = $ids ? \App\Models\Building::whereIn('id', $ids)->with('property')->get()->map(fn ($b) => $b->property->name_ar)->unique()->implode('، ') : 'كل المباني';
        $n = count((array) $a->excluded_unit_ids);
        return $names . ($n ? ' — باستثناء ' . ($n === 1 ? 'وحدة' : ($n === 2 ? 'وحدتين' : $n . ' وحدات')) : '');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('published_at', 'desc')
            ->emptyStateHeading('لا تعاميم بعد')->emptyStateDescription('عمّم على المستأجرين: انقطاع مياه، أو صيانة مصعد، أو تهنئة بالعيد، مع صور أو فيديو، لمبنى أو أكثر، وحدّد متى يختفي التعميم.')
            ->columns([
                TextColumn::make('title_ar')->label('التعميم')->searchable()->weight('bold')
                    ->description(fn (Announcement $r) => \Illuminate\Support\Str::limit($r->body_ar, 90)),
                TextColumn::make('audience')->label('لمن')->state(fn (Announcement $r) => self::audience($r))->wrap(),
                TextColumn::make('state')->label('الحالة')->badge()->state(fn (Announcement $r) => $r->state())
                    ->formatStateUsing(fn ($state) => ['scheduled' => 'مجدول', 'live' => 'معروض الآن', 'ended' => 'انتهى'][$state])
                    ->color(fn ($state) => ['scheduled' => 'info', 'live' => 'success', 'ended' => 'gray'][$state]),
                TextColumn::make('published_at')->label('من')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('expires_at')->label('إلى')->dateTime('Y-m-d H:i')->placeholder('بلا نهاية'),
            ])
            ->recordActions([EditAction::make(),
                \Filament\Actions\Action::make('end')->label('إنهاء الآن')->icon(Heroicon::OutlinedStopCircle)->color('gray')->requiresConfirmation()
                    ->visible(fn (Announcement $r) => $r->state() !== 'ended')
                    ->action(fn (Announcement $r) => $r->forceFill(['expires_at' => now()->max($r->published_at->copy()->addMinute())])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }

    /** The user's property scope (REQ-0494, review M18 A2). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Filament\Support\PropertyScope::apply(parent::getEloquentQuery(), 'building_or_all');
    }
}
