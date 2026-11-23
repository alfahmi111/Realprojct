<x-filament-panels::page>
    @php($bp = fn ($v) => $v === null ? '—' : number_format(intdiv($v, 100)) . '.' . str_pad((string) ($v % 100), 2, '0', STR_PAD_LEFT) . '%')
    @php($m = fn ($v) => \App\Support\Dates\NumberFormatter::money($v))
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        <x-filament::section heading="نسبة الإشغال الفعلية">{{ $bp($kpis['physical_occupancy_bp']) }}</x-filament::section>
        <x-filament::section heading="نسبة الإشغال المالية">{{ $bp($kpis['financial_occupancy_bp']) }}</x-filament::section>
        <x-filament::section heading="معدل التحصيل">{{ $bp($kpis['collection_rate_bp']) }}</x-filament::section>
        <x-filament::section heading="معدل التجديد">{{ $bp($kpis['renewal_rate_bp']) }}</x-filament::section>
        <x-filament::section heading="متوسط مدة الشغور">{{ $kpis['avg_vacancy_days'] ?? '—' }} يوم</x-filament::section>
        <x-filament::section heading="صافي الدخل التشغيلي">{{ $m($kpis['noi']) }}</x-filament::section>
        <x-filament::section heading="تكلفة الصيانة">{{ $m($kpis['maintenance_cost']['total']) }} — لكل وحدة {{ $m($kpis['maintenance_cost']['per_unit']) }}</x-filament::section>
        <x-filament::section heading="أعمار الذمم">
            0–30: {{ $m($kpis['aging']['0_30']) }}<br>31–60: {{ $m($kpis['aging']['31_60']) }}<br>61–90: {{ $m($kpis['aging']['61_90']) }}<br>+90: {{ $m($kpis['aging']['90_plus']) }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
