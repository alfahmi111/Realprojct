@php
    $sum = app(\App\Dashboards\TenantProfiles::class)->summary();
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money((int) $m);
    $base = filament()->getCurrentPanel()->getUrl();
@endphp
<x-filament-panels::page class="wq-page">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>المستأجرون</h1>
            <p>من يسكن أين، ومتى ينتهي عقده، وما المستحق عليه، وكيف تتواصل معه بضغطة.</p>
        </div>
    </header>
    <dl class="wq-ledger">
        <div><dt>كل المستأجرين</dt><dd>{{ $sum['total'] }}</dd><small>أفراد ومنشآت</small></div>
        <div class="is-good"><dt>لهم عقد ساري</dt><dd>{{ $sum['active'] }}</dd><small>يسكنون الآن أو يبدأ عقدهم قريباً</small></div>
        <div class="{{ $sum['arrears'] ? 'is-bad' : '' }}"><dt>عليهم مستحقات</dt><dd>{{ $sum['arrears'] }}</dd><small>المجموع {{ $money($sum['arrears_total']) }}</small></div>
        <div class="{{ $sum['ending'] ? 'is-warn' : '' }}"><dt>تنتهي عقودهم خلال 90 يوماً</dt><dd>{{ $sum['ending'] }}</dd><small>وقت الحديث عن التجديد</small></div>
    </dl>
    {{ $this->table }}
</x-filament-panels::page>
