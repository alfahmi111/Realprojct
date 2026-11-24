<?php

namespace App\Filament\Manager\Pages;

use App\Filament\Support\Ui;
use App\Migration\QuickMigration;
use App\Models\ImportBatch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Quick migration (wizard step 0, REQ-0122, REQ-0317 "استيراد عقد إيجار"): upload → fields with confidence →
 * the manager reviews and corrects → confirm. Nothing is saved before confirmation.
 */
class QuickMigrationPage extends Page
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('properties.import');
    }
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'العقارات';

    protected static ?string $navigationLabel = 'الانتقال السريع';

    protected static ?string $title = 'الانتقال السريع بالذكاء الاصطناعي';

    protected static ?string $slug = 'quick-migration';

    public ?array $upload = [];

    public ?array $review = [];

    public ?int $batchId = null;

    public function mount(): void
    {
        $this->uploadForm->fill();
    }

    protected function getForms(): array
    {
        return ['uploadForm', 'reviewForm'];
    }

    protected string $view = 'filament.manager.quick-migration';

    public function getHeading(): string
    {
        return '';
    }

    public function uploadForm(Schema $schema): Schema
    {
        return $schema->statePath('upload')->components([
            \Filament\Forms\Components\Radio::make('kind')->label('ما الذي ستنقله؟')->required()->inline()
                ->options(['ejar_pdf' => 'عقد إيجار PDF', 'excel' => 'جدول Excel لعدة عقود', 'photo' => 'صورة عقد ورقي'])
                ->descriptions(['ejar_pdf' => 'الملف الذي تنزّله من منصة إيجار', 'excel' => 'صف لكل عقد', 'photo' => 'صورة واضحة بالجوال']),
            FileUpload::make('file')->label('الملف')->disk('local')->directory('imports')->required()
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv'])
                ->helperText('PDF أو صورة أو Excel. يبقى الملف خاصاً بمكتبك.'),
        ]);
    }

    /** Review fields grouped as on the contract; enums shown as Arabic choices, confidence beside each field. */
    public function reviewForm(Schema $schema): Schema
    {
        $conf = collect(ImportBatch::find($this->batchId)?->extracted ?? [])->keyBy('field');
        $hint = function (string $f) use ($conf) {
            if (! $conf->has($f)) {
                return 'لم يُستخرج؛ أدخله يدوياً';
            }
            $c = (int) $conf[$f]['confidence'];
            return $c >= 90 ? "ثقة عالية {$c}%" : ($c >= 70 ? "ثقة متوسطة {$c}%، راجعه" : "ثقة منخفضة {$c}%، تحقّق منه");
        };
        $color = fn (string $f) => ! $conf->has($f) ? 'gray' : ((int) $conf[$f]['confidence'] >= 90 ? 'success' : ((int) $conf[$f]['confidence'] >= 70 ? 'warning' : 'danger'));
        $t = fn (string $f, string $label) => TextInput::make($f)->label($label)->hint($hint($f))->hintColor($color($f));
        $s = fn (string $f, string $label, array $opts) => Select::make($f)->label($label)->options($opts)->hint($hint($f))->hintColor($color($f));
        return $schema->statePath('review')->components([
            \Filament\Schemas\Components\Section::make('المستأجر')->schema([
                $t('tenant_name', 'الاسم'), $s('tenant_kind', 'النوع', ['individual' => 'فرد', 'company' => 'منشأة']), $t('tenant_phone', 'الجوال'),
            ])->columns(3),
            \Filament\Schemas\Components\Section::make('الوحدة والعقد')->schema([
                $t('unit_code', 'رمز الوحدة'), $s('usage', 'الغرض', ['residential' => 'سكني', 'commercial' => 'تجاري']), $t('ejar_contract_number', 'رقم عقد إيجار'),
                $t('starts_on', 'البداية')->placeholder('YYYY-MM-DD'), $t('ends_on', 'النهاية')->placeholder('YYYY-MM-DD'),
            ])->columns(3),
            \Filament\Schemas\Components\Section::make('البيانات المالية')->schema([
                $t('annual_rent', 'الإيجار السنوي'), $s('payment_frequency', 'دورة السداد', ['monthly' => 'شهري', 'quarterly' => 'ربع سنوي', 'semiannual' => 'نصف سنوي', 'annual' => 'سنوي', 'single' => 'دفعة واحدة']),
                $t('deposit', 'مبلغ الضمان'),
            ])->columns(3),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('uploadForm')])->id('upload')->livewireSubmitHandler('stage')->footer([Actions::make([Action::make('stage')->label('استخرج البيانات')->icon(Heroicon::OutlinedSparkles)->submit('stage')])])->visible(fn () => $this->batchId === null),
            Form::make([EmbeddedSchema::make('reviewForm')])->id('review')->livewireSubmitHandler('confirm')->footer([Actions::make([Action::make('confirm')->label('أكّد واحفظ العقد')->submit('confirm'), Action::make('discard')->label('تجاهل هذا الملف')->color('gray')->action('discard')])])->visible(fn () => $this->batchId !== null),
        ]);
    }

    /** Last imports of this office, newest first. */
    public function recent(): array
    {
        return ImportBatch::orderByRaw("status = 'staged' desc")->orderByDesc('id')->limit(10)->get()->map(fn ($b) => [
            'id' => $b->id, 'kind' => ['ejar_pdf' => 'عقد إيجار PDF', 'excel' => 'جدول Excel', 'photo' => 'صورة عقد'][$b->kind] ?? $b->kind,
            'status' => $b->status, 'at' => $b->created_at?->format('Y-m-d H:i'),
            'tenant' => collect($b->reviewed ?? [])->get('tenant_name') ?? collect($b->extracted)->firstWhere('field', 'tenant_name')['value'] ?? null,
            'lease_id' => $b->created_refs['lease_id'] ?? null,
        ])->all();
    }

    public function stage(): void
    {
        $d = $this->uploadForm->getState();
        $path = Storage::disk('local')->path($d['file']);
        // Excel/CSV: the template is read by the platform itself, one contract per row (owner request 2026-11-24)
        if ($d['kind'] === 'excel' && preg_match('/\.(xlsx|csv)$/i', $path)) {
            $batches = Ui::attempt(fn () => app(QuickMigration::class)->stageTemplate(auth()->user(), $path), '');
            if ($batches) {
                \Filament\Notifications\Notification::make()->title(count($batches) === 1 ? 'قُرئ عقد واحد — راجعه قبل الحفظ' : 'قُرئ ' . count($batches) . ' عقداً — راجعها واحداً واحداً')->success()->send();
                $this->openBatch($batches[0]->id);
            }
            return;
        }
        $batch = Ui::attempt(fn () => app(QuickMigration::class)->stage(auth()->user(), $path, $d['kind']), 'استُخرجت الحقول — راجعها قبل الحفظ');
        if ($batch) {
            $this->openBatch($batch->id);
        }
    }

    /** Open a staged batch for review (the next contract of an Excel file, or one left from before). */
    public function openBatch(int $id): void
    {
        $batch = ImportBatch::where('status', 'staged')->findOrFail($id);
        $this->batchId = $batch->id;
        $this->reviewForm->fill(collect($batch->extracted)->mapWithKeys(fn ($f) => [$f['field'] => $f['value']])->all());
    }

    /** After confirming or discarding, continue with the next staged contract of the same file. */
    private function next(?string $file): void
    {
        $this->batchId = null;
        $n = $file ? ImportBatch::where('status', 'staged')->where('file_path', $file)->orderBy('id')->value('id') : null;
        if ($n) {
            $this->openBatch($n);
        }
    }

    public function confirm(): void
    {
        $values = array_filter($this->reviewForm->getState(), fn ($v) => $v !== null && $v !== '');
        $batch = ImportBatch::findOrFail($this->batchId);
        if (Ui::attempt(fn () => app(QuickMigration::class)->confirm($batch, auth()->user(), $values), 'حُفظ العقد')) {
            $this->next($batch->file_path);
        }
    }

    public function discard(): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        app(QuickMigration::class)->discard($batch);
        $this->next($batch->file_path);
    }
}
