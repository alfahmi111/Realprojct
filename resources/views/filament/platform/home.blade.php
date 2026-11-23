@php
    $list = \App\Filament\Platform\Resources\Subscribers\SubscriberResource::getUrl('index');
    $view = fn ($s) => \App\Filament\Platform\Resources\Subscribers\SubscriberResource::getUrl('view', ['record' => $s]);
    $stateAr = \App\Plans\SubscriberAdmin::STATE_AR;
    $planAr = collect(\App\Plans\PlanEntitlements::PLANS)->map(fn ($p) => $p['ar']);
    $maxPlan = max(1, max($o['plans'] ?: [0]));
    $stateTone = ['active' => 'paid', 'expiring' => 'soon', 'grace' => 'soon', 'read_only' => 'late', 'suspended' => 'late', 'no_period' => 'vacant'];
@endphp
<x-filament-panels::page class="wq-home wq-platform">
    <section class="wq-vitals" aria-label="مؤشرات المنصة">
        <a class="wq-vital wq-tone-teal" href="{{ $list }}">
            <span class="wq-vital-label">المشتركون</span>
            <span class="wq-vital-value">{{ $o['total'] }}</span>
            <span class="wq-vital-note">{{ $o['states']['active'] + $o['states']['expiring'] }} اشتراكاً سارياً</span>
        </a>
        <a class="wq-vital wq-tone-late" href="#attention">
            <span class="wq-vital-label">يحتاج انتباهك</span>
            <span class="wq-vital-value">{{ count($o['attention']) }}</span>
            <span class="wq-vital-note">{{ $o['states']['read_only'] }} للقراءة فقط · {{ $o['states']['grace'] }} في المهلة · {{ $o['states']['expiring'] }} ينتهي قريباً</span>
        </a>
        <div class="wq-vital wq-tone-maint">
            <span class="wq-vital-label">الوحدات المُدارة على المنصة</span>
            <span class="wq-vital-value">{{ number_format($o['units']) }}</span>
            <span class="wq-vital-note">{{ number_format($o['users']) }} مستخدماً نشطاً</span>
        </div>
        <div class="wq-vital wq-tone-paid">
            <span class="wq-vital-label">إيراد الاشتراكات الشهري المتوقع</span>
            <span class="wq-vital-value wq-vital-money">{{ \App\Support\Dates\NumberFormatter::money($o['monthly']) }}</span>
            <span class="wq-vital-note">من {{ $o['priced'] }} مشتركاً لهم سعر متفق عليه (السنوي مقسوماً على 12)</span>
        </div>
    </section>

    <section class="wq-states" aria-label="المشتركون حسب الحالة">
        @foreach ($o['states'] as $state => $n)
            <span class="wq-state wq-state-{{ $stateTone[$state] }} {{ $n ? '' : 'is-zero' }}"><strong>{{ $n }}</strong> {{ $stateAr[$state] }}</span>
        @endforeach
    </section>

    <div class="wq-split">
        <section class="wq-panel" id="attention" aria-labelledby="att-h">
            <header class="wq-panel-head"><h2 id="att-h">يحتاج انتباهك</h2><span class="wq-panel-meta">الأكثر إلحاحاً أولاً</span></header>
            @forelse ($o['attention'] as $a)
                <a class="wq-att" href="{{ $view($a['subscriber']) }}">
                    <span class="wq-att-dot wq-state-{{ $stateTone[$a['state']] }}" aria-hidden="true"></span>
                    <span class="wq-att-who"><strong>{{ $a['subscriber']->name_ar }}</strong><small>{{ $a['detail'] }}</small></span>
                    <span class="wq-state wq-state-{{ $stateTone[$a['state']] }}">{{ $stateAr[$a['state']] }}</span>
                </a>
            @empty
                <div class="wq-empty">لا شيء يحتاج انتباهك: كل الاشتراكات سارية ولها مدة.</div>
            @endforelse
        </section>

        <div class="wq-stack">
            <section class="wq-panel" aria-labelledby="plan-h">
                <header class="wq-panel-head"><h2 id="plan-h">الباقات</h2></header>
                <ul class="wq-bars">
                    @foreach ($o['plans'] as $plan => $n)
                        <li><span>{{ $planAr[$plan] ?? $plan }}</span><span class="wq-bar"><span style="--p: {{ intdiv($n * 100, $maxPlan) }}%"></span></span><strong>{{ $n }}</strong></li>
                    @endforeach
                </ul>
            </section>
            <section class="wq-panel" aria-labelledby="hl-h">
                <header class="wq-panel-head"><h2 id="hl-h">جاهزية المنصة</h2><a class="wq-panel-meta" href="{{ \App\Filament\Platform\Pages\PlatformSettingsPage::getUrl() }}">الإعدادات</a></header>
                <ul class="wq-health">
                    @foreach ($o['health'] as $h)
                        <li class="{{ $h['ok'] ? 'is-ok' : 'is-bad' }}">
                            <x-filament::icon :icon="$h['ok'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'" class="wq-health-icon" />
                            <span>{{ $h['label'] }}@if (! $h['ok'] && $h['hint'])<small>{{ $h['hint'] }}</small>@endif</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>

    <section class="wq-panel" aria-labelledby="new-h">
        <header class="wq-panel-head"><h2 id="new-h">أحدث المشتركين</h2><a class="wq-panel-meta" href="{{ $list }}">كل المشتركين</a></header>
        @forelse ($o['latest'] as $s)
            <a class="wq-att" href="{{ $view($s) }}">
                <span class="wq-org-avatar wq-orgt-deep" aria-hidden="true">{{ mb_substr($s->name_ar, 0, 1) }}</span>
                <span class="wq-att-who"><strong>{{ $s->name_ar }}</strong><small>{{ $planAr[$s->plan] ?? $s->plan }}@if ($s->city) · {{ $s->city }}@endif · منذ {{ $s->created_at->format('Y-m-d') }}</small></span>
            </a>
        @empty
            <div class="wq-empty">لا مشتركين بعد. أضف أول مشترك من «المشتركون».</div>
        @endforelse
    </section>
</x-filament-panels::page>
