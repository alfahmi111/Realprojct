@if ($s)
<x-filament::section heading="كشف شهر {{ $s['month'] }}">
    <table style="width:100%">
        <tr><td>إجمالي الإيجار المحصّل</td><td>{{ $s['collected'] }} ر.س</td></tr>
        @if ($s['fee_deducted'])
            <tr><td>ناقص أتعاب الإدارة ({{ $s['fee_pct'] }}% من {{ $s['fee_basis'] }})</td><td>− {{ $s['fee'] }} ر.س</td></tr>
            <tr><td>ناقص ضريبة الأتعاب</td><td>− {{ $s['fee_vat'] }} ر.س</td></tr>
        @else
            <tr><td>أتعاب الإدارة ({{ $s['fee_pct'] }}% من {{ $s['fee_basis'] }}) — تُفوتَر على المالك منفصلة ولا تُخصم هنا</td><td>{{ $s['fee'] }} ر.س + ضريبة {{ $s['fee_vat'] }} ر.س</td></tr>
        @endif
        @foreach ($s['maintenance'] as $m)
            <tr><td>ناقص صيانة: {{ $m['description'] }} @if($m['work_order']) ({{ $m['work_order'] }}) @endif — {{ $m['date'] }}</td><td>− {{ $m['amount'] }} ر.س</td></tr>
        @endforeach
        <tr><td>ناقص هامش المدير على فواتير الموردين (سطر منفصل)</td><td>− {{ $s['margin_total'] }} ر.س</td></tr>
        <tr><th>= صافي المحوَّل للمالك</th><th>{{ $s['net'] }} ر.س</th></tr>
    </table>
</x-filament::section>
@endif
