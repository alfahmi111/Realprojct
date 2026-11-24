@php
    // The header's end (owner request 2026-11-24, like the owner's other app), in reading order:
    // quick "new" · circulars · light/dark · settings · task bell · the user's name and role · avatar · sign out.
    $user = auth()->user();
    $panel = filament()->getCurrentPanel();
    $base = $panel?->getUrl();
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
    $circulars = $isManagerPanel && \App\Filament\Manager\Resources\Announcements\AnnouncementResource::canViewAny()
        ? \App\Filament\Manager\Resources\Announcements\AnnouncementResource::getUrl() : null;
    $settingsUrl = $isManagerPanel && \App\Filament\Manager\Pages\SubscriberSettingsPage::canAccess()
        ? \App\Filament\Manager\Pages\SubscriberSettingsPage::getUrl() : $base . '/my-preferences';
    $settingsLabel = $isManagerPanel && \App\Filament\Manager\Pages\SubscriberSettingsPage::canAccess() ? 'إعدادات المكتب' : 'تفضيلاتي';
    $name = (string) ($user?->name ?? '');
    $initial = $name !== '' ? mb_substr(trim($name), 0, 1) : '؟';
    $role = $panel?->getId() === 'platform' ? 'مدير المنصة' : $user?->role?->labelAr();
    $profile = $panel?->hasProfile() ? $panel->getProfileUrl() : null;
@endphp
<div class="wq-tb-tools">
    @if (count($actions))
        <div class="wq-tb-new" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
            <button type="button" class="wq-tb-btn wq-tb-btn-primary" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true">
                <x-filament::icon icon="heroicon-m-plus" class="wq-tb-ico" /><span class="wq-tb-hide-sm">جديد</span>
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

    @if ($circulars)
        <a class="wq-tb-icon" href="{{ $circulars }}" aria-label="التعاميم" title="التعاميم">
            <x-filament::icon icon="heroicon-o-speaker-wave" class="wq-tb-ico" />
        </a>
    @endif

    <button type="button" class="wq-tb-icon" aria-label="تبديل المظهر الفاتح والداكن" title="المظهر الفاتح / الداكن"
            x-data
            x-on:click="window.dispatchEvent(new CustomEvent('theme-changed', { detail: document.documentElement.classList.contains('dark') ? 'light' : 'dark' }))">
        <x-filament::icon icon="heroicon-o-sun" class="wq-tb-ico wq-tb-sun" />
        <x-filament::icon icon="heroicon-o-moon" class="wq-tb-ico wq-tb-moon" />
    </button>

    <a class="wq-tb-icon" href="{{ $settingsUrl }}" aria-label="{{ $settingsLabel }}" title="{{ $settingsLabel }}">
        <x-filament::icon icon="heroicon-o-cog-6-tooth" class="wq-tb-ico" />
    </a>

    @if ($tasks !== null)
        <a class="wq-tb-icon" href="{{ $base }}/inbox" aria-label="صندوق المهام: {{ $tasks }} {{ $tasks === 1 ? 'مهمة' : 'مهام' }}" title="صندوق المهام">
            <x-filament::icon icon="heroicon-o-bell" class="wq-tb-ico" />
            @if ($tasks > 0)<span class="wq-tb-count">{{ $tasks > 99 ? '99+' : $tasks }}</span>@endif
        </a>
    @endif

    @if ($user)
        <span class="wq-tb-user wq-tb-hide-sm">
            <strong>{{ $name }}</strong>
            @if ($role)<small>{{ $role }}</small>@endif
        </span>
        @if ($profile)
            <a class="wq-tb-avatar" href="{{ $profile }}" aria-label="ملفي الشخصي: {{ $name }}" title="ملفي الشخصي">{{ $initial }}</a>
        @else
            <span class="wq-tb-avatar" aria-hidden="true">{{ $initial }}</span>
        @endif
        <form method="post" action="{{ filament()->getLogoutUrl() }}" class="wq-tb-logout">
            @csrf
            <button type="submit" class="wq-tb-btn" aria-label="تسجيل الخروج">
                <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" class="wq-tb-ico wq-tb-show-sm" />
                <span class="wq-tb-hide-sm">تسجيل الخروج</span>
            </button>
        </form>
    @endif
</div>
