<?php

namespace App\Filament\Manager\Pages;

use App\Filament\Support\Ui;
use App\Finance\TrustAccounting;
use App\Models\Owner;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Owner monthly statement (REQ-0305, REQ-0431): collected − fee (with %) − maintenance per work order − margin = net. */
class OwnerStatementPage extends Page
{

    /** Role permission gate (REQ-0494): hidden from the menu and forbidden by URL without it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('finance.statements');
    }
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $navigationLabel = 'كشف حساب المالك';

    protected static ?string $title = 'كشف حساب المالك الشهري';

    protected static ?string $slug = 'owner-statement';

    public ?array $data = [];

    public ?array $statement = null;

    public function mount(): void
    {
        $this->form->fill(['month' => now()->format('Y-m')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('owner_id')->label('المالك')->options(fn () => Owner::whereNull('archived_at')->pluck('name_ar', 'id'))->required(),
            TextInput::make('month')->label('الشهر (YYYY-MM)')->regex('/^\d{4}-\d{2}$/')->required(),
        ])->columns(2);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('show')->footer([\Filament\Schemas\Components\Actions::make([Action::make('show')->label('عرض الكشف')->submit('show')])]),
            View::make('filament.pages.owner-statement')->viewData(fn () => ['s' => $this->statement]),
        ]);
    }

    public function show(): void
    {
        $d = $this->form->getState();
        $s = Ui::attempt(fn () => app(TrustAccounting::class)->statement(Owner::findOrFail($d['owner_id']), CarbonImmutable::parse($d['month'] . '-01')), 'جاهز');
        $this->statement = $s ? $this->present($s) : null;
    }

    private function present(array $s): array
    {
        $m = fn ($v) => $v->toDecimalString();
        return [
            'month' => $s['month'], 'collected' => $m($s['collected_rent']), 'fee_pct' => intdiv($s['management_fee']['percentage_bp'], 100) . '.' . str_pad((string) ($s['management_fee']['percentage_bp'] % 100), 2, '0', STR_PAD_LEFT),
            'fee' => $m($s['management_fee']['amount']), 'fee_vat' => $m($s['management_fee']['vat']),
            'maintenance' => array_map(fn ($x) => ['date' => $x['date'], 'description' => $x['description'], 'work_order' => $x['work_order'], 'amount' => $m($x['amount'])], $s['maintenance']),
            'maintenance_total' => $m($s['maintenance_total']), 'margin_total' => $m($s['vendor_margin_total']), 'net' => $m($s['net_to_owner']),
        ];
    }
}
