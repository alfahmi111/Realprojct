<x-filament-panels::page>
    <form method="get"><label>الشهر <input type="month" name="month" value="{{ $month }}"></label> <button type="submit">عرض</button></form>

    <x-filament::section heading="طلبات صيانة تجاوزت سقف المدير">
        @forelse ($pending as $wo)
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                <span>{{ $wo->number }} — {{ $wo->description }} — التقدير {{ $wo->estimated_cost_minor?->toDecimalString() }} ر.س</span>
                <x-filament::button size="sm" color="success" wire:click="decide({{ $wo->id }}, true)">موافقة</x-filament::button>
                <x-filament::button size="sm" color="danger" wire:click="decide({{ $wo->id }}, false)">رفض</x-filament::button>
            </div>
        @empty
            <div>لا توجد طلبات.</div>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="كشف الحساب — {{ $month }}">
        @if ($statement)
            <table style="width:100%">
                <tr><td>الإيجار المحصّل</td><td>{{ $statement['collected_rent']->toDecimalString() }} ر.س</td></tr>
                <tr><td>أتعاب الإدارة ({{ intdiv($statement['management_fee']['percentage_bp'], 100) }}.{{ str_pad((string) ($statement['management_fee']['percentage_bp'] % 100), 2, '0', STR_PAD_LEFT) }}% حسب عقد الإدارة)</td><td>− {{ $statement['management_fee']['amount']->toDecimalString() }} ر.س</td></tr>
                <tr><td>ضريبة الأتعاب</td><td>− {{ $statement['management_fee']['vat']->toDecimalString() }} ر.س</td></tr>
                @foreach ($statement['maintenance'] as $m)
                    <tr><td>صيانة: {{ $m['description'] }}</td><td>− {{ $m['amount']->toDecimalString() }} ر.س</td></tr>
                @endforeach
                <tr><td>هامش المدير على فواتير الموردين</td><td>− {{ $statement['vendor_margin_total']->toDecimalString() }} ر.س</td></tr>
                <tr><th>صافي المحوَّل لك</th><th>{{ $statement['net_to_owner']->toDecimalString() }} ر.س</th></tr>
            </table>
        @else
            <div>{{ $error ?? 'لا يوجد ملف مالك مرتبط بحسابك.' }}</div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
