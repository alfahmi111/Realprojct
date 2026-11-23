@php
    $pct = fn ($v) => $v === null ? '—' : intdiv((int) $v, 100);
    $m = fn ($v) => \App\Support\Dates\NumberFormatter::money($v);
    $minor = fn ($v) => $v instanceof \App\Support\Money\Money ? $v->minor() : (int) $v;
    $aging = $kpis['aging'];
    $agingTotal = max(1, array_sum(array_map($minor, $aging)));
    $buckets = ['0_30' => ['حتى 30 يوماً', 'var(--status-vacant-fg)'], '31_60' => ['31 إلى 60', 'var(--status-soon-fg)'], '61_90' => ['61 إلى 90', 'color-mix(in srgb, var(--status-late-fg) 70%, #fff)'], '90_plus' => ['أكثر من 90', 'var(--status-late-fg)']];
    $rates = [
        ['k' => 'physical_occupancy_bp', 'label' => 'الإشغال الفعلي', 'what' => 'الوحدات المؤجرة من كل الوحدات'],
        ['k' => 'financial_occupancy_bp', 'label' => 'الإشغال المالي', 'what' => 'الإيجار الفعلي من الإيجار الممكن لو أُجّر كل شيء'],
        ['k' => 'collection_rate_bp', 'label' => 'التحصيل', 'what' => 'ما حُصّل مما استحق في الفترة'],
        ['k' => 'renewal_rate_bp', 'label' => 'التجديد', 'what' => 'العقود التي جُدّدت مما انتهى في الفترة'],
    ];
@endphp
<x-filament-panels::page class="wq-page wq-kpis">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>مؤشرات الأداء</h1>
            <p>أداء المحفظة {{ $label }}: الإشغال والتحصيل والتجديد، وما دخل وما صُرف، وعمر ما لم يُحصَّل بعد.</p>
        </div>
        <div class="wq-seg" role="group" aria-label="الفترة">
            @foreach (['month' => 'هذا الشهر', 'quarter' => 'هذا الربع', 'year' => 'هذه السنة'] as $k => $l)
                <button type="button" wire:click="$set('period', '{{ $k }}')" @class(['is-on' => $period === $k]) aria-pressed="{{ $period === $k ? 'true' : 'false' }}">{{ $l }}</button>
            @endforeach
        </div>
    </header>

    {{-- Four rates as dials on one strip: the memorable element of the page --}}
    <section class="wq-dials" aria-label="النسب">
        @foreach ($rates as $r)
            @php($v = $kpis[$r['k']])
            <div class="wq-dial">
                <span class="wq-dial-ring" style="--p: {{ $pct($v) === '—' ? 0 : min(100, $pct($v)) }}" role="img" aria-label="{{ $r['label'] }} {{ $pct($v) }}%">
                    <b>{{ $pct($v) }}<small>%</small></b>
                </span>
                <strong>{{ $r['label'] }}</strong>
                <small>{{ $r['what'] }}</small>
            </div>
        @endforeach
    </section>

    <dl class="wq-ledger">
        <div><dt>صافي الدخل التشغيلي</dt><dd>{{ $m($kpis['noi']) }}</dd><small>الإيراد ناقص مصروفات التشغيل، {{ $label }}</small></div>
        <div><dt>تكلفة الصيانة</dt><dd>{{ $m($kpis['maintenance_cost']['total']) }}</dd><small>لكل وحدة {{ $m($kpis['maintenance_cost']['per_unit']) }}</small></div>
        <div><dt>متوسط مدة الشغور</dt><dd>{{ $kpis['avg_vacancy_days'] ?? '—' }}<span class="wq-ledger-of"> يوماً</span></dd><small>بين خروج مستأجر ودخول التالي</small></div>
        <div><dt>أيام شغور تجنّبناها</dt><dd>{{ $kpis['avoided_vacancy_days'] ?? 0 }}<span class="wq-ledger-of"> يوماً</span></dd><small>بفضل قائمة الانتظار</small></div>
    </dl>

    <section class="wq-panel" aria-labelledby="aging-h">
        <header class="wq-panel-head"><h2 id="aging-h">أعمار الذمم</h2><span class="wq-panel-meta">ما لم يُحصَّل بعد، حسب المدة منذ الاستحقاق: {{ $m(array_sum(array_map($minor, $aging))) }}</span></header>
        <div class="wq-aging">
            <div class="wq-aging-bar" role="img" aria-label="توزيع الذمم حسب العمر">
                @foreach ($buckets as $k => [$l, $c])
                    @if ($minor($aging[$k]) > 0)<span style="--w: {{ max(2, intdiv($minor($aging[$k]) * 100, $agingTotal)) }}%; --c: {{ $c }}"></span>@endif
                @endforeach
            </div>
            <ul class="wq-aging-keys">
                @foreach ($buckets as $k => [$l, $c])
                    <li><span class="wq-aging-dot" style="--c: {{ $c }}"></span><span>{{ $l }}</span><b>{{ $m($aging[$k]) }}</b></li>
                @endforeach
            </ul>
        </div>
    </section>

    @if (count($perProperty))
        <section class="wq-panel" aria-labelledby="pp-h">
            <header class="wq-panel-head"><h2 id="pp-h">العقارات جنباً إلى جنب</h2><span class="wq-panel-meta">{{ $label }}</span></header>
            <div class="wq-table-wrap">
                <table class="wq-table">
                    <thead><tr><th>العقار</th><th>الإشغال الفعلي</th><th>الإشغال المالي</th><th>التحصيل</th><th>صافي الدخل</th><th>متأخر أكثر من 90 يوماً</th></tr></thead>
                    <tbody>
                        @foreach ($perProperty as $p)
                            <tr>
                                <td><strong>{{ $p['name'] }}</strong></td>
                                <td><span class="wq-bar"><span style="--p: {{ $pct($p['physical_occupancy_bp']) }}%"></span></span><span class="wq-bar-text">{{ $pct($p['physical_occupancy_bp']) }}%</span></td>
                                <td>{{ $pct($p['financial_occupancy_bp']) }}%</td>
                                <td>{{ $pct($p['collection_rate_bp']) }}%</td>
                                <td>{{ $m($p['noi']) }}</td>
                                <td>{{ $m($p['aging']['90_plus']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-filament-panels::page>
