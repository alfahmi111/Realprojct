@php
    $sum = app(\App\Dashboards\TenantProfiles::class)->summary();
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money((int) $m);
    $base = filament()->getCurrentPanel()->getUrl();
    $types = collect(\App\Properties\UnitTypes::TYPES)->map(fn ($t) => $t['ar']);
    $days = fn (?int $d) => $d === null ? '' : ($d < 0 ? 'انتهى' : 'باقٍ ' . $d . ' يوماً');
@endphp
<x-filament-panels::page class="wq-page">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>المستأجرون</h1>
            <p>من يسكن أين، ومتى ينتهي عقده، وما المستحق عليه، وكيف تتواصل معه بضغطة.</p>
        </div>
        <div class="wq-seg" role="group" aria-label="طريقة العرض">
            <button type="button" wire:click="$set('mode', 'tree')" @class(['is-on' => $mode === 'tree']) aria-pressed="{{ $mode === 'tree' ? 'true' : 'false' }}">حسب المبنى</button>
            <button type="button" wire:click="$set('mode', 'table')" @class(['is-on' => $mode === 'table']) aria-pressed="{{ $mode === 'table' ? 'true' : 'false' }}">عرض الكل</button>
        </div>
    </header>
    <dl class="wq-ledger">
        <div><dt>كل المستأجرين</dt><dd>{{ $sum['total'] }}</dd><small>أفراد ومنشآت</small></div>
        <div class="is-good"><dt>لهم عقد ساري</dt><dd>{{ $sum['active'] }}</dd><small>يسكنون الآن أو يبدأ عقدهم قريباً</small></div>
        <div class="{{ $sum['arrears'] ? 'is-bad' : '' }}"><dt>عليهم مستحقات</dt><dd>{{ $sum['arrears'] }}</dd><small>المجموع {{ $money($sum['arrears_total']) }}</small></div>
        <div class="{{ $sum['ending'] ? 'is-warn' : '' }}"><dt>تنتهي عقودهم خلال 90 يوماً</dt><dd>{{ $sum['ending'] }}</dd><small>وقت الحديث عن التجديد</small></div>
    </dl>

    @if ($mode === 'tree')
        @php($t = $this->tree())
        <label class="wq-search"><x-filament::icon icon="heroicon-m-magnifying-glass" class="wq-search-ic" /><input type="search" wire:model.live.debounce.400ms="q" placeholder="ابحث باسم المستأجر أو جواله أو رقم الوحدة" aria-label="بحث في المستأجرين"></label>
        @forelse ($t['properties'] as $p)
            <details class="wq-node" open>
                <summary class="wq-node-head">
                    <span class="wq-node-photo" @if ($p['photo']) style="background-image:url('{{ $p['photo'] }}')" @endif aria-hidden="true">@unless ($p['photo'])<x-filament::icon icon="heroicon-o-building-office-2" />@endunless</span>
                    <span class="wq-node-title"><strong>{{ $p['name'] }}</strong><small>{{ $p['count'] }} {{ $p['count'] === 1 ? 'مستأجر' : 'مستأجرين' }}</small></span>
                    <span></span><a class="wq-node-link" href="{{ $base }}/properties/{{ $p['id'] }}">صفحة العقار</a>
                </summary>
                @foreach ($p['buildings'] as $b)
                    @if (count($p['buildings']) > 1)<h3 class="wq-node-sub">{{ $b['name'] }}</h3>@endif
                    <ul class="wq-unitlist wq-tenantlist">
                        @foreach ($b['rows'] as $r)
                            <li class="wq-unitrow">
                                <span class="wq-unitrow-code wq-s-{{ $r['status'] }}" title="{{ $r['floor'] }}">{{ $r['unit'] }}</span>
                                <span class="wq-unitrow-main"><a href="{{ $base }}/tenants/{{ $r['tenant_id'] }}/edit"><strong>{{ $r['name'] }}</strong></a><small>{{ $types[$r['type']] ?? $r['type'] }}، {{ $r['floor'] }}</small></span>
                                <span class="wq-unitrow-tenant"><a href="{{ $base }}/leases/{{ $r['lease_id'] }}">العقد حتى {{ $r['ends_on']?->format('Y-m-d') }}</a><small>{{ $days($r['days_left']) }}</small></span>
                                <span class="wq-unitrow-rent {{ $r['outstanding'] > 0 ? 'wq-owes' : '' }}">{{ $r['outstanding'] > 0 ? $money($r['outstanding']) : 'لا شيء' }}<small>{{ $r['outstanding'] > 0 ? 'مستحق عليه' : 'مستحق' }}</small></span>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </details>
        @empty
            @if (! count($t['loose']))
                <section class="wq-panel wq-empty"><p>{{ $q !== '' ? 'لا مستأجر يطابق «' . $q . '».' : 'لا مستأجرين بعد.' }}</p></section>
            @endif
        @endforelse
        @if (count($t['loose']))
            <details class="wq-node">
                <summary class="wq-node-head"><span class="wq-node-photo" aria-hidden="true"><x-filament::icon icon="heroicon-o-user" /></span><span class="wq-node-title"><strong>بلا عقد ساري</strong><small>{{ count($t['loose']) }} مستأجر سابق أو محتمل</small></span><span></span><span></span></summary>
                <ul class="wq-unitlist wq-tenantlist">
                    @foreach ($t['loose'] as $r)
                        <li class="wq-unitrow"><span class="wq-unitrow-code wq-s-vacant">—</span><span class="wq-unitrow-main"><a href="{{ $base }}/tenants/{{ $r['tenant_id'] }}/edit"><strong>{{ $r['name'] }}</strong></a><small dir="ltr">{{ $r['phone'] }}</small></span><span></span><span class="wq-unitrow-rent {{ $r['outstanding'] > 0 ? 'wq-owes' : '' }}">{{ $r['outstanding'] > 0 ? $money($r['outstanding']) : 'لا شيء' }}<small>مستحق</small></span></li>
                    @endforeach
                </ul>
            </details>
        @endif
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
