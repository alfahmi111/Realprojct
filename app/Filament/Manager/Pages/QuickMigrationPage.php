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

    public function uploadForm(Schema $schema): Schema
    {
        return $schema->statePath('upload')->components([
            Select::make('kind')->label('نوع الملف')->options(['ejar_pdf' => 'عقد إيجار PDF', 'excel' => 'جدول Excel', 'photo' => 'صورة عقد ورقي'])->required(),
            FileUpload::make('file')->label('الملف')->disk('local')->directory('imports')->required(),
        ])->columns(2);
    }

    public function reviewForm(Schema $schema): Schema
    {
        $fields = ['tenant_name' => 'اسم المستأجر', 'tenant_kind' => 'نوع المستأجر (individual/company)', 'tenant_phone' => 'الجوال', 'unit_code' => 'رمز الوحدة', 'usage' => 'الاستخدام (residential/commercial)',
            'starts_on' => 'البداية (YYYY-MM-DD)', 'ends_on' => 'النهاية (YYYY-MM-DD)', 'annual_rent' => 'الإيجار السنوي', 'payment_frequency' => 'الدفعات (monthly/quarterly/semiannual/annual/single)', 'deposit' => 'التأمين', 'ejar_contract_number' => 'رقم عقد إيجار'];
        $conf = collect(ImportBatch::find($this->batchId)?->extracted ?? [])->keyBy('field');
        return $schema->statePath('review')->components(collect($fields)->map(fn ($label, $f) => TextInput::make($f)->label($label . ($conf->has($f) ? ' — الثقة ' . $conf[$f]['confidence'] . '%' : '')))->values()->all())->columns(2);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('uploadForm')])->id('upload')->livewireSubmitHandler('stage')->footer([Actions::make([Action::make('stage')->label('استخراج البيانات')->submit('stage')])])->visible(fn () => $this->batchId === null),
            Form::make([EmbeddedSchema::make('reviewForm')])->id('review')->livewireSubmitHandler('confirm')->footer([Actions::make([Action::make('confirm')->label('تأكيد وحفظ')->submit('confirm'), Action::make('discard')->label('تجاهل')->color('gray')->action('discard')])])->visible(fn () => $this->batchId !== null),
        ]);
    }

    public function stage(): void
    {
        $d = $this->uploadForm->getState();
        $batch = Ui::attempt(fn () => app(QuickMigration::class)->stage(auth()->user(), Storage::disk('local')->path($d['file']), $d['kind']), 'استُخرجت الحقول — راجعها قبل الحفظ');
        if ($batch) {
            $this->batchId = $batch->id;
            $this->reviewForm->fill(collect($batch->extracted)->mapWithKeys(fn ($f) => [$f['field'] => $f['value']])->all());
        }
    }

    public function confirm(): void
    {
        $values = array_filter($this->reviewForm->getState(), fn ($v) => $v !== null && $v !== '');
        if (Ui::attempt(fn () => app(QuickMigration::class)->confirm(ImportBatch::findOrFail($this->batchId), auth()->user(), $values), 'حُفظ العقد')) {
            $this->batchId = null;
        }
    }

    public function discard(): void
    {
        app(QuickMigration::class)->discard(ImportBatch::findOrFail($this->batchId));
        $this->batchId = null;
    }
}
