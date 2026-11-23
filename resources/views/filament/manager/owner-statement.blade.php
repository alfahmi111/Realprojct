@php
    $s = $statement;
    $monthName = fn (string $ym) => \IntlDateFormatter::formatObject(\Carbon\CarbonImmutable::parse($ym . '-01'), 'MMMM y', 'ar@calendar=gregorian;numbers=latn');
@endphp
<x-filament-panels::page class="wq-page wq-statement">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>كشف حساب المالك</h1>
            <p>ما حُصّل لكل مالك في الشهر، وما خُصم منه بنداً بنداً، وصافي ما يُحوَّل إليه.</p>
        </div>
        <div class="wq-pager">
            <button type="button" class="wq-btn-quiet" wire:click="shiftMonth(-1)">الشهر السابق</button>
            <button type="button" class="wq-btn-quiet" wire:click="shiftMonth(1)">الشهر التالي</button>
            <button type="button" class="wq-btn-quiet" onclick="window.print()">طباعة</button>
        </div>
    </header>

    <section class="wq-panel wq-migrate-body wq-noprint">{{ $this->content }}</section>

    @if ($s)
        @php($r = $s['raw'])
        @php($max = max(1, $r['collected']))
        @php($left = $r['collected'])
        {{-- waterfall: each deduction is drawn at the end of what remains before it, so the steps walk down to the net --}}
        @php($step = function (int $amount) use (&$left, $max) { $o = intdiv(max(0, $left - $amount) * 100, $max); $left -= $amount; return 'margin-inline-start: ' . $o . '%; width: ' . max(1, intdiv($amount * 100, $max)) . '%'; })
        <article class="wq-panel wq-stmt">
            <header class="wq-stmt-head">
                <div>
                    <small>كشف شهر</small>
                    <h2>{{ $monthName($s['month']) }}</h2>
                    <p>{{ $s['owner'] }}</p>
                </div>
                <div class="wq-stmt-net">
                    <small>صافي المحوَّل للمالك</small>
                    <strong>{{ $s['net'] }}</strong>
                </div>
            </header>

            {{-- Waterfall: from collected down to net, each deduction as its own step --}}
            <ol class="wq-cascade" aria-label="من المحصّل إلى الصافي">
                <li class="is-in"><span class="wq-cascade-label">الإيجار المحصّل</span><span class="wq-cascade-track"><span style="--w: 100%"></span></span><b>{{ $s['collected'] }}</b></li>
                @if ($s['fee_deducted'])
                    <li class="is-out"><span class="wq-cascade-label">أتعاب الإدارة {{ $s['fee_pct'] }}% من {{ $s['fee_basis'] }}</span><span class="wq-cascade-track"><span style="{{ $step($r['fee']) }}"></span></span><b>− {{ $s['fee'] }}</b></li>
                    <li class="is-out"><span class="wq-cascade-label">ضريبة الأتعاب</span><span class="wq-cascade-track"><span style="{{ $step($r['vat']) }}"></span></span><b>− {{ $s['fee_vat'] }}</b></li>
                @endif
                @foreach ($s['maintenance'] as $m)
                    <li class="is-out"><span class="wq-cascade-label">صيانة: {{ $m['description'] }}<small>{{ $m['date'] }}@if ($m['work_order'])، {{ $m['work_order'] }}@endif</small></span><span class="wq-cascade-track"><span style="{{ $step($m['minor']) }}"></span></span><b>− {{ $m['amount'] }}</b></li>
                @endforeach
                @if ($r['margin'])
                    <li class="is-out"><span class="wq-cascade-label">هامش المدير على فواتير الموردين</span><span class="wq-cascade-track"><span style="{{ $step($r['margin']) }}"></span></span><b>− {{ $s['margin_total'] }}</b></li>
                @endif
                <li class="is-net"><span class="wq-cascade-label">صافي المحوَّل للمالك</span><span class="wq-cascade-track"><span style="--w: {{ max(0, min(100, intdiv($r['net'] * 100, $max))) }}%"></span></span><b>{{ $s['net'] }}</b></li>
            </ol>

            @unless ($s['fee_deducted'])
                <p class="wq-note">أتعاب الإدارة ({{ $s['fee_pct'] }}% من {{ $s['fee_basis'] }}) تبلغ {{ $s['fee'] }} وضريبتها {{ $s['fee_vat'] }}، وتُفوتَر على المالك منفصلة فلا تُخصم من هذا الكشف.</p>
            @endunless
            @if (! $r['collected'])
                <p class="wq-note">لم يُحصَّل إيجار لهذا المالك في {{ $monthName($s['month']) }}.</p>
            @endif
        </article>
    @else
        <section class="wq-panel wq-empty"><p>اختر المالك والشهر لعرض الكشف.</p></section>
    @endif
</x-filament-panels::page>
