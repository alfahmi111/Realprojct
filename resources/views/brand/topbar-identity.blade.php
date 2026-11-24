@php
    // Beside the logo: whose workspace this is. The office's name for its people; "إدارة المنصة" for the platform.
    $user = auth()->user();
    $panel = filament()->getCurrentPanel()?->getId();
    $name = null;
    $sub = null;
    if ($panel === 'platform') {
        $name = 'إدارة المنصة';
    } elseif ($user?->subscriber_id) {
        $s = app(\App\Tenancy\TenantContext::class)->run(null, fn () => \App\Models\Subscriber::find($user->subscriber_id));
        $name = $s?->name_ar;
        $sub = $user->role?->labelAr();
        // the office logo beside its name (owner request 2026-11-24)
        $logo = app(\App\Settings\Settings::class)->get('theme.logo_light') ? route('office.logo', [$user->subscriber_id, 'light']) : null;
        $logoDark = app(\App\Settings\Settings::class)->get('theme.logo_dark') ? route('office.logo', [$user->subscriber_id, 'dark']) : null;
    }
@endphp
@if ($name)
    <span class="wq-tb-identity" title="{{ $name }}">
        <span class="wq-tb-sep" aria-hidden="true"></span>
        @if (! empty($logo))<img class="wq-tb-logo wq-tb-logo-light" src="{{ $logo }}" alt="شعار {{ $name }}">@if (! empty($logoDark))<img class="wq-tb-logo wq-tb-logo-dark" src="{{ $logoDark }}" alt="شعار {{ $name }}">@else<img class="wq-tb-logo wq-tb-logo-dark wq-tb-logo-backdrop" src="{{ $logo }}" alt="شعار {{ $name }}">@endif @endif
        <span class="wq-tb-office">{{ $name }}</span>
        @if ($sub)<span class="wq-tb-role">{{ $sub }}</span>@endif
    </span>
@endif
