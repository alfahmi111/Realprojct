@php
    $sar = fn ($m) => \App\Support\Dates\NumberFormatter::money($m);
    $collected = $statement ? max(1, $statement['collected_rent']->minor()) : 1;
    $share = fn ($m) => min(100, round($m->minor() * 100 / $collected, 1)) . '%';
@endphp
<x-filament-panels::page class="wq-portal">
    <section class="wq-hello" aria-label="ترحيب">
        <div class="wq-hello-who">
            <span class="wq-hello-avatar" aria-hidden="true">{{ mb_substr($header['name'], 0, 1) }}</span>
            <div>
                <h1 class="wq-hello-title">{{ $header['greeting'] }}، {{ $header['name'] }}</h1>
                <p class="wq-hello-sub">
                    @if (count($pending))
                        {{ count($pending) === 1 ? 'طلب صيانة واحد ينتظر موافقتك' : count($pending) . ' طلبات صيانة تنتظر موافقتك' }}
                    @else
                        لا طلبات تنتظر موافقتك
                    @endif
                    <span class="wq-hello-role">{{ $header['role'] }}</span>
                </p>
            </div>
        </div>
        <div class="wq-hello-date">
            <span class="wq-hello-day">{{ $header['day'] }}</span>
            <strong class="wq-hello-hijri">{{ $header['hijri'] }}</strong>
            <span class="wq-hello-greg">{{ $header['gregorian'] }}</span>
        </div>
    </section>

    <div class="wq-grid">
        {{-- Statement: rent flows down, each deduction shown with its share, the net stands out --}}
        <section class="wq-panel" aria-labelledby="st-h">
            <header class="wq-panel-head">
                <h2 id="st-h">كشف الحساب — {{ $monthLabel }}</h2>
                <form method="get" class="wq-month">
                    <label class="wq-field"><span class="wq-sr">الشهر</span>
                        <select class="wq-input" name="month" aria-label="الشهر" onchange="this.form.submit()">@foreach ($months as $value => $label)<option value="{{ $value }}" @selected($value === $month)>{{ $label }}</option>@endforeach</select></label>
                    <x-filament::button type="submit" color="gray" size="sm">عرض</x-filament::button>
                </form>
            </header>
            @if ($statement)
                <ul class="wq-fall">
                    <li class="wq-fall-in">
                        <span class="wq-fall-label">الإيجار المحصّل</span>
                        <span class="wq-fall-amt">{{ $sar($statement['collected_rent']) }}</span>
                        <span class="wq-fall-bar"><span style="--p:100%"></span></span>
                    </li>
                    @php($fee = $statement['management_fee'])
                    @php($feeNote = intdiv($fee['percentage_bp'], 100) . '.' . str_pad((string) ($fee['percentage_bp'] % 100), 2, '0', STR_PAD_LEFT) . '% من ' . (($fee['basis'] ?? 'collected') === 'due' ? 'الإيجار المستحق' : 'المحصَّل') . ' حسب عقد الإدارة')
                    @if ($fee['deducted'] ?? true)
                        <li class="wq-fall-minus">
                            <span class="wq-fall-label">أتعاب الإدارة <small>{{ $feeNote }}</small></span>
                            <span class="wq-fall-amt">− {{ $sar($fee['amount']) }}</span>
                            <span class="wq-fall-bar"><span style="--p:{{ $share($fee['amount']) }}"></span></span>
                        </li>
                        <li class="wq-fall-minus">
                            <span class="wq-fall-label">ضريبة الأتعاب</span>
                            <span class="wq-fall-amt">− {{ $sar($fee['vat']) }}</span>
                            <span class="wq-fall-bar"><span style="--p:{{ $share($fee['vat']) }}"></span></span>
                        </li>
                    @else
                        <li class="wq-fall-info">
                            <span class="wq-fall-label">أتعاب الإدارة <small>{{ $feeNote }} — تصلك بفاتورة منفصلة ولا تُخصم من هذا المبلغ</small></span>
                            <span class="wq-fall-amt">{{ $sar($fee['amount']->plus($fee['vat'])) }}</span>
                        </li>
                    @endif
                    @foreach ($statement['maintenance'] as $m)
                        <li class="wq-fall-minus">
                            <span class="wq-fall-label">صيانة: {{ $m['description'] }} <small>{{ $m['work_order'] ?? '' }} · {{ $m['date'] ?? '' }}</small></span>
                            <span class="wq-fall-amt">− {{ $sar($m['amount']) }}</span>
                            <span class="wq-fall-bar"><span style="--p:{{ $share($m['amount']) }}"></span></span>
                        </li>
                    @endforeach
                    <li class="wq-fall-minus">
                        <span class="wq-fall-label">هامش المدير على فواتير الموردين</span>
                        <span class="wq-fall-amt">− {{ $sar($statement['vendor_margin_total']) }}</span>
                        <span class="wq-fall-bar"><span style="--p:{{ $share($statement['vendor_margin_total']) }}"></span></span>
                    </li>
                    <li class="wq-fall-net">
                        <span class="wq-fall-label">صافي المحوَّل لك</span>
                        <span class="wq-fall-amt">{{ $sar($statement['net_to_owner']) }}</span>
                    </li>
                </ul>
            @else
                <div class="wq-empty">{{ $error ?? 'لا يوجد ملف مالك مرتبط بحسابك. تواصل مع مكتب الإدارة لربطه.' }}</div>
            @endif
        </section>

        {{-- Approvals above the manager's spending cap (REQ-0307) --}}
        <section class="wq-panel" aria-labelledby="ap-h">
            <header class="wq-panel-head"><h2 id="ap-h">طلبات صيانة تجاوزت سقف المدير</h2></header>
            @forelse ($pending as $wo)
                <article class="wq-ask">
                    <div class="wq-ask-top">
                        <strong>{{ $wo->description }}</strong>
                        <span class="wq-ask-num">{{ $wo->number }}</span>
                    </div>
                    <div class="wq-ask-top">
                        <span class="wq-muted">التكلفة التقديرية</span>
                        <span class="wq-ask-cost">{{ $wo->estimated_cost_minor ? $sar($wo->estimated_cost_minor) : '—' }}</span>
                    </div>
                    <div class="wq-ask-actions">
                        <x-filament::button color="success" icon="heroicon-m-check" wire:click="decide({{ $wo->id }}, true)">موافقة</x-filament::button>
                        <x-filament::button color="danger" outlined icon="heroicon-m-x-mark" wire:click="decide({{ $wo->id }}, false)">رفض</x-filament::button>
                    </div>
                </article>
            @empty
                <div class="wq-empty">لا توجد طلبات تنتظر قرارك. سيصلك إشعار عند أي طلب يتجاوز السقف المتفق عليه.</div>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
