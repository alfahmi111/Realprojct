{{--
    The office's organisation chart. Used by the platform subscriber page (mode "platform": account-support actions)
    and the office's own "الهيكل التنظيمي" page (mode "office"). Drag a card onto another to change who it reports to;
    the "نقل" action does the same from the keyboard. $this is the Livewire page (moveUser, actions).
--}}
<div class="wq-org" x-data="{ dragging: null, over: null }"
     x-on:dragend.window="dragging = null; over = null">
    @if (count($tree['roots']))
        <p class="wq-org-hint">اسحب بطاقة موظف وأفلتها على من يتبع له، أو استعمل زر «نقل». لا يتبع أحد لمن هو أدنى منه صلاحية.</p>
        <div class="wq-org-scroll">
            <ul class="wq-org-level wq-org-top">
                @foreach ($tree['roots'] as $node)
                    @include('filament.partials.team-node', ['node' => $node, 'mode' => $mode])
                @endforeach
            </ul>
        </div>
    @else
        <div class="wq-empty">لا يوجد موظفون في المكتب بعد.</div>
    @endif

    @if (count($tree['external']))
        <section class="wq-org-external" aria-labelledby="ext-h">
            <h3 id="ext-h">حسابات البوابات <small>ملاك ومستأجرون، خارج الهيكل التنظيمي</small></h3>
            <ul>
                @foreach ($tree['external'] as $u)
                    <li class="wq-org-ext {{ $u['active'] ? '' : 'is-off' }}">
                        <span class="wq-org-avatar wq-orgt-{{ $u['role'] === 'tenant' ? 'soon' : 'deep' }}" aria-hidden="true">{{ mb_substr($u['name'], 0, 1) }}</span>
                        <span class="wq-org-ext-who"><strong>{{ $u['name'] }}</strong><small>{{ $u['role_ar'] }}@unless ($u['active']) · موقوف@endunless</small></span>
                        @if ($mode === 'platform')
                            <span class="wq-org-actions">
                                {{ ($this->setPasswordAction)(['user' => $u['id']]) }}
                                @if ($u['two_factor']){{ ($this->resetTwoFactorAction)(['user' => $u['id']]) }}@endif
                                {{ ($this->toggleUserAction)(['user' => $u['id'], 'active' => $u['active']]) }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
