@php
    // Right before the user menu: today's date (Hijri first), quick "new" actions and the task bell for office staff.
    $user = auth()->user();
    $panel = filament()->getCurrentPanel();
    $base = $panel?->getUrl();
    $now = \Carbon\CarbonImmutable::now();
    $tz = 'Asia/Riyadh';
    try {
        $tz = app(\App\Settings\Settings::class)->get('date.timezone') ?? $tz;
    } catch (\Throwable) {
    }
    $dates = app(\App\Support\Dates\DateService::class);
    $day = \IntlDateFormatter::formatObject($now->setTimezone($tz), 'EEEE', 'ar_SA');
    $hijri = $dates->format($now, 'hijri', $tz);
    $greg = $dates->format($now, 'gregorian', $tz);
    $isManagerPanel = $panel?->getId() === 'manager';
    $actions = $isManagerPanel && $user ? array_values(array_filter(\App\Filament\Manager\Pages\HomeDashboard::quickActions(), fn ($a) => $user->hasPermission($a['perm']))) : [];
    $tasks = null;
    if ($isManagerPanel && $user && \App\Filament\Manager\Pages\InboxPage::canAccess()) {
        try {
            $tasks = array_sum(app(\App\Dashboards\ManagerInbox::class)->for($user)['counts']);
        } catch (\Throwable) {
            $tasks = null;
        }
    }
@endphp
<div class="wq-tb-tools">
    <span class="wq-tb-date" title="{{ $day }} {{ $hijri }} — {{ $greg }}">
        <x-filament::icon icon="heroicon-o-calendar-days" class="wq-tb-ico" />
        <span><strong>{{ $hijri }}</strong><small>{{ $day }} · {{ $greg }}</small></span>
    </span>

    @if (count($actions))
        <div class="wq-tb-new" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
            <button type="button" class="wq-tb-btn wq-tb-btn-primary" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true">
                <x-filament::icon icon="heroicon-m-plus" class="wq-tb-ico" /><span>جديد</span>
            </button>
            <div class="wq-tb-menu" x-show="open" x-transition.opacity x-cloak role="menu">
                @foreach ($actions as $a)
                    <a role="menuitem" href="{{ $base }}/{{ $a['url'] }}" class="wq-tb-menu-item">
                        <x-filament::icon :icon="$a['icon']" class="wq-tb-ico" />
                        <span><strong>{{ $a['label'] }}</strong><small>{{ $a['hint'] }}</small></span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($tasks !== null)
        <a class="wq-tb-btn wq-tb-bell" href="{{ $base }}/inbox" aria-label="صندوق المهام: {{ $tasks }} {{ $tasks === 1 ? 'مهمة' : 'مهام' }}">
            <x-filament::icon icon="heroicon-o-bell" class="wq-tb-ico" />
            @if ($tasks > 0)<span class="wq-tb-count">{{ $tasks > 99 ? '99+' : $tasks }}</span>@endif
        </a>
    @endif
</div>
