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
                    <small>صافي الشهر للمالك</small>
                    <strong>{{ $s['net'] }}</strong>
                </div>
            </header>

            {{-- Waterfall: from collected down to net, each deduction as its own step --}}
            <ol class="wq-cascade" aria-label="من المحصّل إلى الصافي">
                <li class="is-in"><span class="wq-cascade-label">الإيجار المحصّل</span><span class="wq-cascade-track"><span style="--w: 100%"></span></span><b>{{ $s['collected'] }}</b></li>
                @if ($r['owner_direct'])<li class="is-out"><span class="wq-cascade-label">حُصّل في حساب المالك مباشرة<small>لا يمر بحساب المكتب، فلا يُحوَّل</small></span><span class="wq-cascade-track"><span style="{{ $step($r['owner_direct']) }}"></span></span><b>− {{ $s['owner_direct'] }}</b></li>@endif
                @if ($s['fee_deducted'])
                    <li class="is-out"><span class="wq-cascade-label">أتعاب الإدارة: {{ $s['fee_text'] }}</span><span class="wq-cascade-track"><span style="{{ $step($r['fee']) }}"></span></span><b>− {{ $s['fee'] }}</b></li>
                    <li class="is-out"><span class="wq-cascade-label">ضريبة الأتعاب</span><span class="wq-cascade-track"><span style="{{ $step($r['vat']) }}"></span></span><b>− {{ $s['fee_vat'] }}</b></li>
                @endif
                @foreach ($s['maintenance'] as $m)
                    <li class="is-out"><span class="wq-cascade-label">صيانة: {{ $m['description'] }}<small>{{ $m['date'] }}@if ($m['work_order'])، {{ $m['work_order'] }}@endif</small></span><span class="wq-cascade-track"><span style="{{ $step($m['minor']) }}"></span></span><b>− {{ $m['amount'] }}</b></li>
                @endforeach
                @if ($r['margin'])
                    <li class="is-out"><span class="wq-cascade-label">هامش المدير على فواتير الموردين</span><span class="wq-cascade-track"><span style="{{ $step($r['margin']) }}"></span></span><b>− {{ $s['margin_total'] }}</b></li>
                @endif
                @if ($r['recovered'])<li class="is-in"><span class="wq-cascade-label">صيانة استُردت من المستأجر<small>تكلفة حُمّلت على المستأجر وسدّدها</small></span><span class="wq-cascade-track"><span style="--w: {{ max(1, intdiv($r['recovered'] * 100, $max)) }}%"></span></span><b>+ {{ $s['recovered'] }}</b></li>@endif
                <li class="is-net"><span class="wq-cascade-label">صافي الشهر للمالك<small>يُحوَّل بطلب يعتمده مراجِع؛ انظر حركة الأمانات أدناه</small></span><span class="wq-cascade-track"><span style="--w: {{ max(0, min(100, intdiv($r['net'] * 100, $max))) }}%"></span></span><b>{{ $s['net'] }}</b></li>
            </ol>

            {{-- M22: what was actually transferred and what is still available, carried from month to month --}}
            <section class="wq-trust" aria-label="حركة أمانات المالك">
                <h3>حركة الأمانات</h3>
                <dl>
                    <dt>متاح من الأشهر السابقة<small>صافي ما سبق ناقص ما حُوّل منه</small></dt><dd>{{ $s['opening_available'] }}</dd>
                    <dt>صافي هذا الشهر</dt><dd>{{ $s['net'] }}</dd>
                    @forelse ($s['disbursements'] as $t)
                        <dt>حُوّل للمالك<small>{{ $t['date'] }}</small></dt><dd class="is-out">− {{ $t['amount'] }}</dd>
                    @empty
                        <dt>حُوّل للمالك هذا الشهر</dt><dd>{{ $s['disbursed'] }}</dd>
                    @endforelse
                    <dt class="is-total">رصيد المالك في نهاية الشهر<small>سالب = مستحق على المالك للمكتب</small></dt><dd class="is-total">{{ $s['available'] }}</dd>
                </dl>
                {{-- now: the cash held for the owner, what the office is owed out of it, and what can be transferred — apart --}}
                <h3>الآن</h3>
                <dl>
                    <dt>النقد في أمانات المكتب لحساب المالك<small>من قيود الدفتر</small></dt><dd>{{ $s['trust_cash'] }}</dd>
                    <dt>مستحق للمكتب لم يُقتطع<small>أتعاب {{ $s['fees_owed'] }} وهامش موردين {{ $s['margins_owed'] }}</small></dt><dd class="is-out">− {{ $s['owed_to_office'] }}</dd>
                    @if ($s['has_owner_owes'])<dt>مستحق على المالك للمكتب<small>المستحق أكبر من النقد المحفوظ له</small></dt><dd class="is-out">{{ $s['owner_owes'] }}</dd>@endif
                </dl>
                <div class="wq-trust-now">
                    <div>
                        <small>المتاح للصرف الآن من أمانات المكتب</small>
                        <strong>{{ $s['available_now'] }}</strong>
                        @if ($s['has_pending'])<small>بانتظار الاعتماد: طلبات تحويل بمبلغ {{ $s['pending_disbursements'] }}</small>@endif
                        @if ($r['owner_direct'])<small>ما قبضه المالك في حسابه مباشرة ({{ $s['owner_direct'] }}) ليس من هذا الرصيد.</small>@endif
                        @if ($s['has_difference'])<small class="is-out" role="alert">فرق بين الدفتر والكشوف قدره {{ $s['difference'] }}؛ يُصرف الأقل منهما حتى تُراجَع القيود.</small>@endif
                    </div>
                    <div class="wq-noprint">{{ $this->disburseAction }}</div>
                </div>
            </section>

            @if ($s['owner_paid'])
                {{-- Q37 (أ): paid by the owner to the vendor himself — not deducted above, not from trust --}}
                <section class="wq-trust" aria-label="صيانة دفعها المالك">
                    <h3>صيانة دفعها المالك للمورّد مباشرة</h3>
                    <dl>
                        @foreach ($s['owner_paid'] as $op)
                            <dt>{{ $op['work_order'] }}<small>{{ $op['date'] }}</small></dt><dd>{{ $op['amount'] }}</dd>
                        @endforeach
                        <dt class="is-total">المجموع<small>لا يُخصم من صافي الشهر ولا من الأمانات</small></dt><dd class="is-total">{{ $s['owner_paid_total'] }}</dd>
                    </dl>
                </section>
            @endif

            @unless ($s['fee_deducted'])
                <p class="wq-note">أتعاب الإدارة ({{ $s['fee_text'] }}) تبلغ {{ $s['fee'] }} وضريبتها {{ $s['fee_vat'] }}، وتُفوتَر على المالك منفصلة فلا تُخصم من هذا الكشف.</p>
            @endunless
            @if (! $r['collected'])
                <p class="wq-note">لم يُحصَّل إيجار لهذا المالك في {{ $monthName($s['month']) }}.</p>
            @endif
        </article>
    @else
        <section class="wq-panel wq-empty"><p>اختر المالك والشهر لعرض الكشف.</p></section>
    @endif
</x-filament-panels::page>
