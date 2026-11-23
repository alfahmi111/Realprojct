<?php

namespace App\Filament\Platform\Pages;

use App\Filament\Support\Ui;
use App\Models\HijriCorrection;
use App\Support\Dates\DateService;
use App\Support\Dates\HijriCorrections;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use IntlDateFormatter;

/**
 * Manual Hijri corrections (Q19, owner's decision 2026-11-23, amending decision 13; REQ-0346). When an official
 * sighting differs from Umm al-Qura, the platform admin records the official Hijri date of that Gregorian day. It
 * applies at once to every screen and document of every subscriber.
 */
class HijriCorrectionsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'تصحيح التاريخ الهجري';

    protected static ?string $title = 'تصحيح التاريخ الهجري';

    protected static ?string $slug = 'hijri-corrections';

    public ?array $data = [];

    public function mount(): void
    {
        $today = app(DateService::class)->calculatedHijriOfDate(CarbonImmutable::now('Asia/Riyadh')->toDateString());
        $this->form->fill(['gregorian' => null, 'year' => $today['year'], 'month' => $today['month'], 'day' => null, 'note' => null, 'rest_of_month' => true]);
    }

    public function form(Schema $schema): Schema
    {
        $dates = app(DateService::class);
        return $schema->statePath('data')->components([
            Section::make('تصحيح يوم')
                ->description('يُستخدم عند إعلان رؤية تخالف حساب أم القرى. التصحيح بيوم واحد قبل الحساب أو بعده، ويسري فوراً على كل الشاشات والمستندات لكل المشتركين.')
                ->schema([
                    DatePicker::make('gregorian')->label('التاريخ الميلادي')->required()->native(true)->live()
                        ->helperText(function ($state) use ($dates) {
                            if (! $state) {
                                return null;
                            }
                            $h = $dates->calculatedHijriOfDate((string) $state);
                            return sprintf('حساب أم القرى لهذا اليوم: %d %s %d هـ', $h['day'], $this->monthName($h['year'], $h['month']), $h['year']);
                        }),
                    Grid::make(3)->schema([
                        Select::make('day')->label('اليوم الهجري الصحيح')->required()->options(array_combine(range(1, 30), range(1, 30))),
                        Select::make('month')->label('الشهر')->required()->options(fn () => $this->monthOptions((int) ($this->data['year'] ?? 1448))),
                        TextInput::make('year')->label('السنة الهجرية')->required()->integer()->minValue(1300)->maxValue(1600)->live(onBlur: true),
                    ]),
                    TextInput::make('note')->label('السبب أو المرجع')->placeholder('مثال: إعلان المحكمة العليا دخول شهر رمضان')->maxLength(500),
                    Toggle::make('rest_of_month')->label('طبّق على بقية الشهر')->default(true)
                        ->helperText('تغيّر بداية الشهر يزيح كل أيامه يوماً واحداً، فيُسجَّل التصحيح لكل يوم حتى اليوم 29 أو حتى يتطابق الحساب. اليوم 30 تحدده رؤية الشهر التالي.'),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([Actions::make([Action::make('save')->label('حفظ التصحيح')->submit('save')])]),
            EmbeddedTable::make(),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $svc = app(HijriCorrections::class);
        $args = [(string) $d['gregorian'], (int) $d['year'], (int) $d['month'], (int) $d['day'], $d['note'] ?? null];
        $saved = ($d['rest_of_month'] ?? false)
            ? Ui::attempt(fn () => $svc->recordRestOfMonth(...$args), 'حُفظ التصحيح لبقية الشهر، ويسري الآن على كل الشاشات')
            : Ui::attempt(fn () => $svc->record(...$args), 'حُفظ التصحيح، ويسري الآن على كل الشاشات');
        if ($saved) {
            $this->form->fill(['gregorian' => null, 'year' => $d['year'], 'month' => $d['month'], 'day' => null, 'note' => null, 'rest_of_month' => true]);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('التصحيحات المسجلة')
            ->query(HijriCorrection::query()->orderByDesc('gregorian_date'))
            ->emptyStateHeading('لا توجد تصحيحات')
            ->emptyStateDescription('التواريخ الهجرية كلها تتبع حساب أم القرى.')
            ->columns([
                TextColumn::make('gregorian_date')->label('الميلادي')->date('Y-m-d'),
                TextColumn::make('calculated_hijri')->label('حساب أم القرى'),
                TextColumn::make('corrected')->label('الهجري المعتمد')->state(fn (HijriCorrection $r) => $r->hijri())->weight('bold'),
                TextColumn::make('note')->label('السبب')->placeholder('—')->wrap(),
                TextColumn::make('updated_at')->label('آخر تعديل')->since(),
            ])
            ->recordActions([
                Action::make('remove')->label('إلغاء التصحيح')->color('danger')->icon(Heroicon::OutlinedTrash)->requiresConfirmation()
                    ->modalDescription('يعود هذا اليوم إلى حساب أم القرى في كل الشاشات.')
                    ->action(fn (HijriCorrection $record) => Ui::attempt(fn () => app(HijriCorrections::class)->remove($record), 'أُلغي التصحيح')),
            ]);
    }

    private function monthOptions(int $year): array
    {
        $out = [];
        foreach (range(1, 12) as $m) {
            $out[$m] = $m . ' — ' . $this->monthName($year, $m);
        }
        return $out;
    }

    private function monthName(int $year, int $month): string
    {
        try {
            $g = app(DateService::class)->hijriToGregorian($year, $month, 1);
        } catch (\Throwable) {
            return (string) $month;
        }
        $fmt = new IntlDateFormatter('ar_SA@calendar=' . DateService::HIJRI_CALENDAR . ';numbers=latn', IntlDateFormatter::NONE, IntlDateFormatter::NONE, new \DateTimeZone('UTC'), IntlDateFormatter::TRADITIONAL, 'MMMM');
        return (string) $fmt->format(CarbonImmutable::parse($g . ' 12:00:00', 'UTC')->getTimestamp());
    }
}
