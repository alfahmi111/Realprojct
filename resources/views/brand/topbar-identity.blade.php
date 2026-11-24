@php
    // Beside the logo (owner request 2026-11-24, header like the owner's other app): the office logo, then the current
    // page title in large type with today's date under it (Hijri first). The page's own <h1> stays for screen readers.
    $user = auth()->user();
    $panel = filament()->getCurrentPanel()?->getId();
    $office = null;
    $logo = $logoDark = null;
    if ($panel === 'platform') {
        $office = 'إدارة المنصة';
    } elseif ($user?->subscriber_id) {
        $s = app(\App\Tenancy\TenantContext::class)->run(null, fn () => \App\Models\Subscriber::find($user->subscriber_id));
        $office = $s?->name_ar;
        $logo = app(\App\Settings\Settings::class)->get('theme.logo_light') ? route('office.logo', [$user->subscriber_id, 'light']) : null;
        $logoDark = app(\App\Settings\Settings::class)->get('theme.logo_dark') ? route('office.logo', [$user->subscriber_id, 'dark']) : null;
    }
    $tz = config('app.display_timezone', 'Asia/Riyadh');
    try {
        $tz = app(\App\Settings\Settings::class)->get('date.timezone') ?? $tz;
    } catch (\Throwable) {
    }
    $now = \Carbon\CarbonImmutable::now();
    $dates = app(\App\Support\Dates\DateService::class);
    $day = \IntlDateFormatter::formatObject($now->setTimezone($tz), 'EEEE', 'ar_SA');
    $hijri = $dates->format($now, 'hijri', $tz);
    $greg = $dates->format($now, 'gregorian', $tz);
@endphp
<span class="wq-tb-identity">
    @if ($logo)
        <img class="wq-tb-logo wq-tb-logo-light" src="{{ $logo }}" alt="شعار {{ $office }}" title="{{ $office }}">
        @if ($logoDark)<img class="wq-tb-logo wq-tb-logo-dark" src="{{ $logoDark }}" alt="شعار {{ $office }}" title="{{ $office }}">@else<img class="wq-tb-logo wq-tb-logo-dark wq-tb-logo-backdrop" src="{{ $logo }}" alt="شعار {{ $office }}" title="{{ $office }}">@endif
    @endif
    <span class="wq-tb-sep" aria-hidden="true"></span>
    <span class="wq-tb-heading"
          x-data="{ t: '' }"
          x-init="const f = () => { const h = document.querySelector('.fi-header-heading'); t = (h ? h.textContent : document.title.split(' - ')[0]).trim() }; f(); document.addEventListener('livewire:navigated', f)">
        <strong class="wq-tb-title" x-text="t" aria-hidden="true"></strong>
        <small class="wq-tb-sub" title="{{ $day }} {{ $hijri }} — {{ $greg }}">@if ($office && ! $logo){{ $office }} · @endif{{ $day }} {{ $hijri }}</small>
    </span>
</span>
