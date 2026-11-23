@php
    $d = $this->data();
    $p = $d['p'];
    $t = $d['tree'];
    $k = $d['kpis'];
    $a = $d['assets'];
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money($m);
    $pct = fn ($bp) => $bp === null ? '—' : intdiv((int) $bp, 100) . '%';
    $base = filament()->getCurrentPanel()->getUrl();
    $labels = ['paid' => 'مؤجرة ومسددة', 'late' => 'عليها متأخرات', 'vacant' => 'شاغرة', 'maint' => 'تحت الصيانة', 'soon' => 'ستشغر'];
    $WO = \App\Filament\Manager\Resources\WorkOrders\WorkOrderResource::class;
@endphp
<x-filament-panels::page class="wq-page">
    <header class="wq-prophead">
        <div class="wq-prophead-photo" @if ($p->photoUrl()) style="background-image:url('{{ $p->photoUrl() }}')" @endif>
            @unless ($p->photoUrl())<x-filament::icon icon="heroicon-o-building-office-2" />@endunless
        </div>
        <div class="wq-prophead-text">
            <a class="wq-back" href="{{ $base }}/properties">العقارات</a>
            <h1>{{ $p->name_ar }}</h1>
            <p>{{ $p->city ?: 'بلا مدينة' }}@if ($p->deed_number)، صك {{ $p->deed_number }}@endif@if ($p->portfolio)، محفظة {{ $p->portfolio->name_ar }}@endif</p>
            <p class="wq-prop-owners">
                @forelse ($p->shares as $s){{ $s->owner->name_ar }}@if ($s->share_numerator != $s->share_denominator) ({{ $s->share_numerator }}/{{ $s->share_denominator }})@endif{{ $loop->last ? '' : '، ' }}@empty لم يُربط مالك @endforelse
            </p>
        </div>
        <nav class="wq-pager">
            <a class="wq-btn-quiet" href="{{ $base }}/properties/{{ $p->id }}/edit">تعديل</a>
            <a class="wq-btn-quiet" href="{{ $base }}/building-contents">المحتوى والجرد</a>
            <a class="wq-btn-quiet" href="{{ $base }}/owner-statement">كشف المالك</a>
        </nav>
    </header>

    <dl class="wq-ledger">
        <div><dt>الإشغال</dt><dd>{{ $pct($k['physical_occupancy_bp']) }}</dd><small>{{ $t['counts']['leased'] ?? 0 }} من {{ $t['counts']['total'] ?? 0 }} وحدة</small></div>
        <div><dt>التحصيل هذا الشهر</dt><dd>{{ $pct($k['collection_rate_bp']) }}</dd><small>مما استحق في الشهر</small></div>
        <div class="{{ ($k['aging']['90_plus'] ?? null) && (is_object($k['aging']['90_plus']) ? $k['aging']['90_plus']->minor() : $k['aging']['90_plus']) > 0 ? 'is-bad' : '' }}"><dt>متأخر أكثر من 90 يوماً</dt><dd>{{ $money($k['aging']['90_plus']) }}</dd><small>من ذمم المستأجرين</small></div>
        <div><dt>قيمة الأصول الحالية</dt><dd>{{ $a ? $money($a['totals']['book']) : '—' }}</dd><small>@if ($a)إهلاك سنوي {{ $money($a['totals']['yearly']) }}@endif</small></div>
    </dl>

    <section class="wq-panel">
        <header class="wq-panel-head"><h2>واجهة العقار اليوم</h2><a class="wq-link" href="{{ $base }}/leasable-units">كل الوحدات</a></header>
        @if ($t)
            <div class="wq-facade-mini">
                @foreach ($t['buildings'] as $b)
                    @foreach ($b['floors'] as $f)
                        <div class="wq-facade-row">
                            <span class="wq-facade-floor">{{ $f['name'] }}</span>
                            <div class="wq-facade-units">
                                @foreach ($f['units'] as $u)
                                    <a class="wq-unitrow-code wq-s-{{ $u['status'] }}" href="{{ $u['lease'] ? $base . '/leases/' . $u['lease']['id'] : $base . '/lease-offers/create' }}" title="{{ $u['code'] }}: {{ $labels[$u['status']] }}{{ $u['lease'] ? '، ' . $u['lease']['tenant'] : '' }}">{{ $u['code'] }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </div>
            <ul class="wq-legend">@foreach ($labels as $key => $l)<li><span class="wq-dot wq-s-{{ $key }}"></span>{{ $l }} <b>{{ $t['counts'][$key] ?? 0 }}</b></li>@endforeach</ul>
        @else
            <p class="wq-calm">لم يُنشأ مبنى لهذا العقار بعد.</p>
        @endif
    </section>

    <div class="wq-sheet-two">
        <section class="wq-panel">
            <header class="wq-panel-head"><h2>أوامر عمل مفتوحة</h2><a class="wq-link" href="{{ $base }}/work-orders">كل أوامر العمل</a></header>
            <ul class="wq-nearlist">
                @forelse ($d['wo'] as $w)
                    <li class="wq-rec">
                        <span class="wq-near-what"><a href="{{ $base }}/work-orders/{{ $w->id }}"><strong>{{ $w->description }}</strong></a><small>{{ $w->space?->name_ar ?? 'المبنى عموماً' }}، {{ $w->reported_at?->format('Y-m-d') }}</small></span>
                        <span class="wq-chip {{ $w->status === 'reported' ? 'wq-s-late' : 'wq-st-draft' }}">{{ $WO::STATUS_AR[$w->status] ?? $w->status }}</span>
                        <span class="wq-near-cost">{{ $w->priority ? $WO::PRIORITY_AR[$w->priority] : '' }}</span>
                    </li>
                @empty
                    <li class="wq-calm">لا أوامر عمل مفتوحة.</li>
                @endforelse
            </ul>
        </section>
        <section class="wq-panel">
            <header class="wq-panel-head"><h2>هذا الشهر</h2></header>
            <dl class="wq-fields wq-fields-2">
                <div><dt>صافي الدخل التشغيلي</dt><dd>{{ $money($k['noi']) }}</dd></div>
                <div><dt>تكلفة الصيانة</dt><dd>{{ $money($k['maintenance_cost']['total']) }}</dd></div>
                <div><dt>الإشغال المالي</dt><dd>{{ $pct($k['financial_occupancy_bp']) }}</dd></div>
                <div><dt>صُرف على الصيانة هذا العام</dt><dd>{{ $a ? $money($a['totals']['spend_year']) : '—' }}</dd></div>
            </dl>
        </section>
    </div>
</x-filament-panels::page>
