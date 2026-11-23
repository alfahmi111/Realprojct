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
    }
@endphp
@if ($name)
    <span class="wq-tb-identity" title="{{ $name }}">
        <span class="wq-tb-sep" aria-hidden="true"></span>
        <span class="wq-tb-office">{{ $name }}</span>
        @if ($sub)<span class="wq-tb-role">{{ $sub }}</span>@endif
    </span>
@endif
