@php
    $pct = fn (int $bp) => intdiv($bp, 100);
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money($m);
    $statusLabels = ['paid' => 'مؤجرة ومسددة', 'late' => 'عليها متأخرات', 'vacant' => 'شاغرة', 'maint' => 'تحت الصيانة', 'soon' => 'ستشغر'];
@endphp
<x-filament-panels::page class="wq-home">
    {{-- Greeting band: who, when, and how much is waiting --}}
    <section class="wq-hello" aria-label="ترحيب">
        <div class="wq-hello-who">
            <span class="wq-hello-avatar" aria-hidden="true">{{ mb_substr($header['name'], 0, 1) }}</span>
            <div>
                <h1 class="wq-hello-title">{{ $header['greeting'] }}، {{ $header['name'] }}</h1>
                <p class="wq-hello-sub">
                    @if (count($attention))
                        {{ count($attention) === 1 ? 'أمر واحد يحتاجك اليوم' : (count($attention) === 2 ? 'أمران يحتاجانك اليوم' : count($attention) . ' أمور تحتاجك اليوم') }}
                    @else
                        لا شيء معلّق؛ المحفظة على ما يرام
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

    {{-- First-run setup: shown until every step the user may do is done --}}
    @php($setupDone = collect($setup)->where('done', true)->count())
    @if (count($setup) && $setupDone < count($setup))
        <section class="wq-panel wq-setup" aria-labelledby="setup-h">
            <header class="wq-panel-head">
                <h2 id="setup-h">جهّز مكتبك للعمل</h2>
                <span class="wq-panel-meta">{{ $setupDone }} من {{ count($setup) }} خطوات</span>
            </header>
            <div class="wq-setup-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($setup) }}" aria-valuenow="{{ $setupDone }}" aria-label="تقدّم الإعداد"><span style="--p: {{ intdiv($setupDone * 100, count($setup)) }}%"></span></div>
            <ol class="wq-setup-list">
                @foreach ($setup as $step)
                    <li class="wq-setup-step {{ $step['done'] ? 'is-done' : '' }}">
                        <span class="wq-setup-mark" aria-hidden="true">@if ($step['done'])<x-filament::icon icon="heroicon-m-check" class="wq-setup-check" />@else{{ $loop->iteration }}@endif</span>
                        <span class="wq-setup-text">
                            @if ($step['done'])
                                <strong>{{ $step['label'] }}</strong><span class="wq-sr"> (منجزة)</span>
                            @else
                                <a href="{{ $base }}/{{ $step['url'] }}"><strong>{{ $step['label'] }}</strong></a>
                            @endif
                            <small>{{ $step['hint'] }}</small>
                        </span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- Vitals --}}
    <section class="wq-vitals" aria-label="مؤشرات المحفظة">
        <a class="wq-vital wq-tone-teal" href="{{ $base }}/kpis">
            <span class="wq-vital-label">نسبة الإشغال</span>
            <span class="wq-vital-value">{{ $pct($vitals['occupancy']['bp']) }}<small>%</small></span>
            <span class="wq-vital-note">{{ $vitals['occupancy']['leased'] }} من {{ $vitals['occupancy']['total'] }} وحدة مؤجرة</span>
        </a>
        <a class="wq-vital wq-tone-paid" href="{{ $base }}/kpis">
            <span class="wq-vital-label">معدل التحصيل هذا العام</span>
            <span class="wq-vital-value">{{ $pct($vitals['collection']['bp']) }}<small>%</small></span>
            <span class="wq-vital-note">من المستحق منذ بداية السنة</span>
        </a>
        <a class="wq-vital wq-tone-late" href="{{ $base }}/invoices">
            <span class="wq-vital-label">متأخرات مستحقة</span>
            <span class="wq-vital-value wq-vital-money">{{ $money($vitals['overdue']['amount']) }}</span>
            <span class="wq-vital-note">على {{ $vitals['overdue']['leases'] === 1 ? 'عقد واحد' : ($vitals['overdue']['leases'] === 2 ? 'عقدين' : $vitals['overdue']['leases'] . ' عقود') }}، منها {{ $money($vitals['overdue']['over90']) }} تجاوزت 90 يوماً</span>
        </a>
        <a class="wq-vital wq-tone-maint" href="{{ $base }}/work-orders">
            <span class="wq-vital-label">أوامر عمل مفتوحة</span>
            <span class="wq-vital-value">{{ $vitals['work_orders']['open'] }}</span>
            <span class="wq-vital-note">{{ $vitals['work_orders']['emergency'] ? $vitals['work_orders']['emergency'] . ' طارئة' : 'لا بلاغات طارئة' }}</span>
        </a>
    </section>

    <div class="wq-split">
        {{-- The building facade: every unit, today --}}
        <section class="wq-panel wq-facade-panel" aria-label="واجهة المبنى">
            <header class="wq-panel-head">
                <h2>واجهة المبنى اليوم</h2>
                @if (count($buildings) > 1)
                    <select class="wq-select" wire:model.live="building" aria-label="اختر المبنى">
                        @foreach ($buildings as $b)<option value="{{ $b['id'] }}" @selected($b['id'] === $buildingId)>{{ $b['name'] }}</option>@endforeach
                    </select>
                @elseif (count($buildings) === 1)
                    <span class="wq-panel-meta">{{ $buildings[0]['name'] }}</span>
                @endif
            </header>
            @if ($facade && count($facade['rows']))
                <div class="wq-facade" role="list" style="--cols: {{ max(1, collect($facade['rows'])->max(fn ($r) => count($r['units']))) }}">
                    @foreach ($facade['rows'] as $row)
                        <div class="wq-floor" role="listitem">
                            <span class="wq-floor-name">{{ $row['floor'] }}</span>
                            <div class="wq-floor-units">
                                @foreach ($row['units'] as $u)
                                    <a class="wq-unit wq-s-{{ $u['status'] }}" href="{{ $base }}/leasable-units?tableSearch={{ urlencode($u['code']) }}"
                                       title="{{ $u['code'] }} — {{ $statusLabels[$u['status']] }}{{ $u['tenant'] ? ' — ' . $u['tenant'] : '' }}">
                                        <span class="wq-unit-code">{{ $u['code'] }}</span>
                                        <span class="wq-sr">{{ $statusLabels[$u['status']] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <div class="wq-ground" aria-hidden="true"></div>
                </div>
                <ul class="wq-legend">
                    @foreach ($statusLabels as $k => $label)
                        <li><span class="wq-dot wq-s-{{ $k }}"></span>{{ $label }} <b>{{ $facade['counts'][$k] }}</b></li>
                    @endforeach
                </ul>
            @else
                <div class="wq-empty">
                    <p>لم تُضف مبانٍ بعد. ابدأ بالمعالج: يولّد الطوابق والوحدات في دقيقة.</p>
                    @if (auth()->user()->hasPermission('properties.create'))<a class="wq-btn" href="{{ $base }}/add-building">إضافة مبنى</a>@endif
                </div>
            @endif
        </section>

        {{-- Needs attention --}}
        <section class="wq-panel wq-attention" aria-label="يحتاج انتباهك">
            <header class="wq-panel-head"><h2>يحتاج انتباهك</h2></header>
            @forelse ($attention as $a)
                <a class="wq-task wq-t-{{ $a['tone'] }}" href="{{ $base }}/{{ $a['url'] }}">
                    <span class="wq-task-count">{{ $a['count'] }}</span>
                    <span class="wq-task-text"><strong>{{ $a['title'] }}</strong><small>{{ $a['hint'] }}</small></span>
                </a>
            @empty
                <p class="wq-calm">لا اعتمادات ولا بلاغات ولا متأخرات تنتظرك.</p>
            @endforelse
        </section>
    </div>

    @if (count($actions))
        <section class="wq-panel" aria-label="إجراءات سريعة">
            <header class="wq-panel-head"><h2>إجراءات سريعة</h2></header>
            <div class="wq-actions">
                @foreach ($actions as $a)
                    <a class="wq-action" href="{{ $base }}/{{ $a['url'] }}">
                        <x-filament::icon :icon="$a['icon']" class="wq-action-icon" />
                        <span class="wq-action-label">{{ $a['label'] }}</span>
                        <span class="wq-action-hint">{{ $a['hint'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if (count($renewals))
        <section class="wq-panel" aria-label="عقود تقترب مهلة إشعارها">
            <header class="wq-panel-head">
                <h2>عقود تقترب مهلة إشعارها</h2>
                <span class="wq-panel-meta">تتجدد تلقائياً ما لم يُشعر أحد الطرفين قبل المهلة</span>
            </header>
            <div class="wq-table-wrap">
                <table class="wq-table">
                    <thead><tr><th>الوحدة</th><th>المستأجر</th><th>ينتهي العقد</th><th>آخر موعد للإشعار</th><th class="wq-col-bar">المتبقي</th></tr></thead>
                    <tbody>
                        @foreach ($renewals as $r)
                            <tr>
                                <td><a href="{{ $base }}/leases/{{ $r['lease_id'] }}">{{ $r['unit'] }}</a></td>
                                <td>{{ $r['tenant'] }}</td>
                                <td>{{ $r['ends_on'] }}</td>
                                <td>{{ $r['deadline'] }}</td>
                                <td class="wq-col-bar">
                                    @if ($r['notice'])
                                        <span class="wq-chip wq-s-soon">أُشعر بعدم التجديد</span>
                                    @elseif ($r['days'] < 0)
                                        <span class="wq-chip wq-s-paid">فاتت المهلة؛ سيتجدد تلقائياً</span>
                                    @else
                                        <span class="wq-bar {{ $r['days'] <= 15 ? 'wq-urgent' : ($r['days'] <= 45 ? 'wq-near' : '') }}" style="--p: {{ $r['elapsed'] }}%"><span></span></span>
                                        <span class="wq-bar-text">{{ $r['days'] }} {{ $r['days'] === 1 ? 'يوم' : ($r['days'] <= 10 ? 'أيام' : 'يوماً') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-filament-panels::page>
