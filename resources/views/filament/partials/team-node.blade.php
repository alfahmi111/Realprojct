<li class="wq-org-node">
    <article class="wq-org-card wq-orgt-{{ $node['tone'] }} {{ $node['active'] ? '' : 'is-off' }} {{ $node['implied'] ? 'is-implied' : '' }}"
             draggable="true"
             x-on:dragstart="dragging = {{ $node['id'] }}; $event.dataTransfer.effectAllowed = 'move'"
             x-on:dragover.prevent="if (dragging && dragging !== {{ $node['id'] }}) over = {{ $node['id'] }}"
             x-on:dragleave="if (over === {{ $node['id'] }}) over = null"
             x-on:drop.prevent="if (dragging && dragging !== {{ $node['id'] }}) $wire.moveUser(dragging, {{ $node['id'] }}); over = null"
             x-bind:class="{ 'is-drop': over === {{ $node['id'] }}, 'is-dragging': dragging === {{ $node['id'] }} }"
             aria-label="{{ $node['name'] }} — {{ $node['role_ar'] }}">
        <header class="wq-org-head">
            <span class="wq-org-avatar" aria-hidden="true">{{ mb_substr($node['name'], 0, 1) }}</span>
            <span class="wq-org-who">
                <strong>{{ $node['name'] }}</strong>
                <small>{{ $node['role_ar'] }}</small>
            </span>
            @if ($node['department'])<span class="wq-org-dept">{{ $node['department'] }}</span>@endif
        </header>
        <p class="wq-org-meta">
            @unless ($node['active'])<span class="wq-org-flag is-bad">موقوف</span>@endunless
            <span>{{ $node['last_login_at'] ? 'دخل ' . $node['last_login_at']->diffForHumans() : 'لم يدخل بعد' }}</span>
            <span class="wq-org-flag {{ $node['two_factor'] ? 'is-ok' : '' }}">{{ $node['two_factor'] ? 'التحقق مفعّل' : 'التحقق غير مُعَدّ' }}</span>
        </p>
        @if ($node['implied'])<p class="wq-org-note">لم يُحدَّد من يتبع له بعد</p>@endif
        <footer class="wq-org-actions">
            {{ ($this->moveUserAction)(['user' => $node['id']]) }}
            @if ($mode === 'platform')
                {{ ($this->setPasswordAction)(['user' => $node['id']]) }}
                @if ($node['two_factor']){{ ($this->resetTwoFactorAction)(['user' => $node['id']]) }}@endif
                {{ ($this->toggleUserAction)(['user' => $node['id'], 'active' => $node['active']]) }}
            @else
                {{ ($this->editUserAction)(['user' => $node['id']]) }}
            @endif
        </footer>
    </article>
    @if (count($node['children']))
        <ul class="wq-org-level">
            @foreach ($node['children'] as $child)
                @include('filament.partials.team-node', ['node' => $child, 'mode' => $mode])
            @endforeach
        </ul>
    @endif
</li>
