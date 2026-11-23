<?php

namespace App\Filament\Manager\Resources\Documents;

use App\Documents\DocumentVault;
use App\Models\Building;
use App\Models\Document;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Space;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * خزنة المستندات (REQ-0054, REQ-0265…REQ-0270; owner order Q30): every document with its owner (property, building,
 * unit, owner, tenant), expiry state, version, uploader and views. Party documents only with documents.parties.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'العقارات';

    protected static ?string $modelLabel = 'مستند';

    protected static ?string $pluralModelLabel = 'خزنة المستندات';

    protected static ?string $slug = 'documents';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('properties.view');
    }

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

    /** "property:3" style keys for the owner picker. */
    public static function ownerOptions(): array
    {
        $o = [];
        foreach (Property::whereNull('archived_at')->orderBy('name_ar')->get() as $p) {
            $o['العقارات']["property:{$p->id}"] = $p->name_ar;
        }
        foreach (Building::whereNull('archived_at')->with('property')->get() as $b) {
            $o['المباني']["building:{$b->id}"] = $b->property?->name_ar . ' — ' . $b->name_ar;
        }
        foreach (Space::where('kind', 'unit')->whereNull('archived_at')->with('property')->orderBy('code')->get() as $s) {
            $o['الوحدات']["space:{$s->id}"] = $s->code . ' — ' . $s->property?->name_ar;
        }
        if (auth()->user()?->hasPermission('documents.parties')) {
            foreach (Owner::whereNull('archived_at')->orderBy('name_ar')->get() as $w) {
                $o['الملاك']["owner:{$w->id}"] = $w->name_ar;
            }
            foreach (Tenant::whereNull('archived_at')->orderBy('name_ar')->get() as $t) {
                $o['المستأجرون']["tenant:{$t->id}"] = $t->name_ar;
            }
        }
        return $o;
    }

    public static function resolveOwner(string $key): Model
    {
        [$type, $id] = explode(':', $key);
        return match ($type) {
            'property' => Property::findOrFail($id), 'building' => Building::findOrFail($id), 'space' => Space::findOrFail($id),
            'owner' => Owner::findOrFail($id), 'tenant' => Tenant::findOrFail($id),
        };
    }

    public static function ownerLabel(Document $d): string
    {
        $m = match ($d->documentable_type) {
            'property' => Property::find($d->documentable_id)?->name_ar,
            'building' => ($b = Building::with('property')->find($d->documentable_id)) ? $b->property?->name_ar . ' — ' . $b->name_ar : null,
            'space' => ($s = Space::find($d->documentable_id)) ? 'الوحدة ' . $s->code : null,
            'owner' => Owner::find($d->documentable_id)?->name_ar,
            'tenant' => Tenant::find($d->documentable_id)?->name_ar,
            default => null,
        };
        return $m ?? '—';
    }

    public static function fileField(): FileUpload
    {
        return FileUpload::make('file')->label('الملف')->disk('local')->visibility('private')->required()->maxSize(10240)
            ->directory(fn () => 'documents/' . (auth()->user()?->subscriber_id ?? 'x'))
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->helperText('PDF أو صورة حتى 10 ميغابايت.');
    }

    public static function table(Table $table): Table
    {
        $today = now()->startOfDay();
        $vault = fn () => app(DocumentVault::class);
        return $table->modifyQueryUsing(function ($query) {
            $query->current()->with('uploader')->orderByRaw('expires_on IS NULL, expires_on');
            if (! auth()->user()?->hasPermission('documents.parties')) {
                $query->where('category', '<>', 'party');
            }
        })
            ->emptyStateHeading('الخزنة فارغة')->emptyStateDescription('ارفع صك العقار ورخصه وشهاداته النظامية. ما له تاريخ انتهاء ينبّهك النظام قبله بثلاثين يوماً ثم بسبعة، وشهادة الدفاع المدني أو فحص المصاعد تفتح أمر صيانة نظامية تلقائياً.')
            ->columns([
                TextColumn::make('title_ar')->label('المستند')->searchable()->weight('bold')
                    ->description(fn (Document $d) => DocumentVault::CATEGORY_AR[$d->category] . ($d->version > 1 ? '، النسخة ' . $d->version : '')),
                TextColumn::make('owner')->label('يخص')->state(fn (Document $d) => self::ownerLabel($d)),
                TextColumn::make('expires_on')->label('الانتهاء')->placeholder('بلا انتهاء')->badge()
                    ->formatStateUsing(fn ($state) => ($d = (int) $today->diffInDays($state, false)) < 0 ? 'منتهٍ منذ ' . abs($d) . ' يوماً' : ($d === 0 ? 'ينتهي اليوم' : 'باقٍ ' . $d . ' يوماً'))
                    ->color(fn ($state) => ($d = (int) $today->diffInDays($state, false)) < 0 ? 'danger' : ($d <= 30 ? 'warning' : 'success'))
                    ->description(fn (Document $d) => $d->expires_on?->format('Y-m-d')),
                TextColumn::make('uploader.name')->label('رفعه')->description(fn (Document $d) => $d->created_at?->format('Y-m-d')),
                TextColumn::make('statutory_work_order_id')->label('أمر صيانة نظامية')->placeholder('—')
                    ->formatStateUsing(fn ($state) => \App\Models\WorkOrder::find($state)?->number)
                    ->url(fn (Document $d) => $d->statutory_work_order_id ? \App\Filament\Manager\Resources\WorkOrders\WorkOrderResource::getUrl('view', ['record' => $d->statutory_work_order_id]) : null),
            ])
            ->filters([
                SelectFilter::make('category')->label('التصنيف')->options(DocumentVault::CATEGORY_AR),
                SelectFilter::make('property_id')->label('العقار')->relationship('property', 'name_ar'),
                SelectFilter::make('expiry')->label('الانتهاء')->options(['expired' => 'منتهية', '30' => 'خلال 30 يوماً', '90' => 'خلال 90 يوماً'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'expired' => $query->where('expires_on', '<', now()->toDateString()),
                        '30', '90' => $query->whereBetween('expires_on', [now()->toDateString(), now()->addDays((int) $data['value'])->toDateString()]),
                        default => $query,
                    }),
            ])
            ->headerActions([
                Action::make('upload')->label('رفع مستند')->icon(Heroicon::OutlinedArrowUpTray)
                    ->schema([
                        Select::make('owner')->label('يخص')->options(fn () => self::ownerOptions())->searchable()->required(),
                        Select::make('kind')->label('النوع')->required()->live()
                            ->options(fn () => collect(DocumentVault::KINDS)->filter(fn ($k) => $k[0] !== 'party' || auth()->user()?->hasPermission('documents.parties'))
                                ->groupBy(fn ($k) => DocumentVault::CATEGORY_AR[$k[0]], preserveKeys: true)->map(fn ($g) => $g->map(fn ($k) => $k[1]))->toArray()),
                        TextInput::make('title')->label('العنوان (اختياري)')->placeholder('يُستعمل اسم النوع إن تركته'),
                        DatePicker::make('expires_on')->label('تاريخ الانتهاء')->helperText('اتركه فارغاً إن لم يكن للمستند انتهاء.'),
                        self::fileField(),
                    ])
                    ->action(function (array $data) use ($vault) {
                        try {
                            $vault()->add(self::resolveOwner($data['owner']), $data['kind'], (string) ($data['title'] ?? ''), $data['file'], $data['expires_on'] ?? null, auth()->user());
                            Notification::make()->title('رُفع المستند')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('تعذّر الرفع')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('open')->label('فتح')->icon(Heroicon::OutlinedEye)->url(fn (Document $d) => route('media.document', $d->id), shouldOpenInNewTab: true),
                Action::make('replace')->label('نسخة جديدة')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->modalDescription('للترخيص المجدَّد أو الشهادة الجديدة. تبقى النسخة السابقة في السجل.')
                    ->schema([DatePicker::make('expires_on')->label('تاريخ الانتهاء الجديد'), self::fileField()])
                    ->action(function (Document $d, array $data) use ($vault) {
                        $vault()->replace($d, $data['file'], $data['expires_on'] ?? null, auth()->user());
                        Notification::make()->title('حُفظت النسخة الجديدة')->success()->send();
                    }),
                Action::make('log')->label('السجل')->icon(Heroicon::OutlinedClock)->color('gray')->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                    ->modalContent(fn (Document $d) => view('filament.manager.document-log', ['doc' => $d, 'versions' => $vault()->history($d), 'views' => $d->views()->with('user')->latest('viewed_at')->limit(20)->get()])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocuments::route('/')];
    }
}
