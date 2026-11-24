@php
    // The header's end (owner request 2026-11-24, like the owner's other app), in reading order:
    // quick "new" · notification sound on/off · light/dark · settings · task bell · the user's name and role · avatar · sign out.
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

    {{-- notification sound on/off (owner request 2026-11-24, as in the previous system): a chime when a task or a
         notification arrives; the choice is kept on this device --}}
    <button type="button" class="wq-tb-icon" x-data="wqSound()" x-on:click="toggle()"
            x-bind:aria-pressed="on ? 'true' : 'false'" x-bind:aria-label="on ? 'صوت التنبيهات مفعّل، اضغط للكتم' : 'صوت التنبيهات مكتوم، اضغط للتفعيل'"
            x-bind:title="on ? 'كتم صوت التنبيهات' : 'تفعيل صوت التنبيهات'">
        <span x-show="on"><x-filament::icon icon="heroicon-o-speaker-wave" class="wq-tb-ico" /></span>
        <span x-show="! on" x-cloak><x-filament::icon icon="heroicon-o-speaker-x-mark" class="wq-tb-ico" /></span>
    </button>
    <script>
        window.wqSound ??= () => ({
            on: (() => { try { return localStorage.getItem('wq:sound') !== 'off'; } catch (e) { return true; } })(),
            init() {
                if (window.__wqPulse) return;          // one poller per page, even after SPA navigation
                window.__wqPulse = true;
                let last = null;
                const poll = async () => {
                    try {
                        const r = await fetch(@js(route('pulse')), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        if (!r.ok) return;
                        const d = await r.json();
                        const n = (d.tasks || 0) + (d.unread || 0);
                        if (last !== null && n > last && window.wqSoundOn()) window.wqChime();
                        last = n;
                        const c = document.querySelector('.wq-tb-count');
                        if (c && d.tasks > 0) c.textContent = d.tasks > 99 ? '99+' : d.tasks;
                    } catch (e) {}
                };
                poll();
                setInterval(poll, 45000);
            },
            toggle() {
                this.on = ! this.on;
                try { localStorage.setItem('wq:sound', this.on ? 'on' : 'off'); } catch (e) {}
                if (this.on) window.wqChime();         // a sample, so the user hears what they enabled
            },
        });
        window.wqSoundOn ??= () => { try { return localStorage.getItem('wq:sound') !== 'off'; } catch (e) { return true; } };
        window.wqChime ??= () => {                     // two soft tones, no audio file
            try {
                const a = new (window.AudioContext || window.webkitAudioContext)();
                [[880, 0], [1320, 0.16]].forEach(([f, t]) => {
                    const o = a.createOscillator(), g = a.createGain();
                    o.type = 'sine'; o.frequency.value = f;
                    g.gain.setValueAtTime(0.0001, a.currentTime + t);
                    g.gain.exponentialRampToValueAtTime(0.18, a.currentTime + t + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, a.currentTime + t + 0.35);
                    o.connect(g).connect(a.destination); o.start(a.currentTime + t); o.stop(a.currentTime + t + 0.4);
                });
            } catch (e) {}
        };
    </script>

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
