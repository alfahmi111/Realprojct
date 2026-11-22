<x-filament-panels::page>
    @php($colors = ['leased' => 'var(--status-paid-bg, #E3F0E0)', 'vacant' => 'var(--status-vacant-bg, #FBEED3)', 'held' => 'var(--status-soon-bg, #E5E2F2)', 'maintenance' => 'var(--status-maint-bg, #ECE8E3)'])
    <form method="get" style="display:flex;gap:8px;align-items:end">
        <label>العقار <select name="property">@foreach ($properties as $id => $name)<option value="{{ $id }}" @selected($id == $pid)>{{ $name }}</option>@endforeach</select></label>
        <label>من <input type="date" name="from" value="{{ $start->toDateString() }}"></label>
        <button type="submit">عرض</button>
    </form>
    <div style="overflow-x:auto">
        <table style="border-collapse:collapse;font-size:12px">
            <tr><th>الوحدة</th>@foreach (array_keys(reset($grid)['days'] ?? []) as $d)<th style="padding:2px">{{ substr($d, 8) }}</th>@endforeach<th>سيشغر</th></tr>
            @foreach ($grid as $code => $row)
                <tr><td style="padding:2px 6px">{{ $code }}</td>
                    @foreach ($row['days'] as $state)<td title="{{ $state }}" style="width:18px;height:18px;background:{{ $colors[$state] }}"></td>@endforeach
                    <td>@if ($row['vacating']) خلال {{ $row['vacating']['in_days'] }} يوم ({{ $row['vacating']['confidence'] === 'confirmed' ? 'مؤكد' : $row['vacating']['confidence'] }}) @endif</td>
                </tr>
            @endforeach
        </table>
    </div>
    <p>الألوان: مؤجر • شاغر • محجوز مبدئياً • تحت الصيانة — ألوان الحالة ثابتة في كل السمات.</p>
</x-filament-panels::page>
