@php
    $tree = $this->tree();
    $money = fn ($m) => $m === null ? '—' : \App\Support\Dates\NumberFormatter::money($m);
    $statusAr = \App\Filament\Manager\Resources\Leases\LeaseResource::STATUS_AR;
    $base = filament()->getCurrentPanel()->getUrl();
    $days = fn (int $d) => $d === 1 ? 'يوم' : ($d === 2 ? 'يومان' : ($d <= 10 ? $d . ' أيام' : $d . ' يوماً'));
@endphp
<x-filament-panels::page class="wq-page">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>العقود</h1>
            <p>كل عقار ثم وحداته، ولكل وحدة مستأجرها الحالي وعقده وما بقي منه، والعقود السابقة تحتها.</p>
        </div>
        <div class="wq-seg" role="group" aria-label="طريقة العرض">
            <button type="button" wire:click="$set('mode', 'tree')" @class(['is-on' => $mode === 'tree']) aria-pressed="{{ $mode === 'tree' ? 'true' : 'false' }}">الشجرة</button>
            <button type="button" wire:click="$set('mode', 'table')" @class(['is-on' => $mode === 'table']) aria-pressed="{{ $mode === 'table' ? 'true' : 'false' }}">كل العقود والإجراءات</button>
        </div>
    </header>

    @if ($mode === 'tree')
        <div class="wq-treebar">
            <label class="wq-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="wq-search-ic" />
                <input type="search" wire:model.live.debounce.400ms="q" placeholder="ابحث باسم المستأجر أو جواله أو رقم الوحدة أو رقم عقد إيجار" aria-label="بحث في العقود">
            </label>
            <label class="wq-check"><input type="checkbox" wire:model.live="onlyLeased"> الوحدات المؤجرة فقط</label>
        </div>

        @forelse ($tree as $p)
            <details class="wq-node" open>
                <summary class="wq-node-head">
                    <span class="wq-node-photo" @if ($p['photo']) style="background-image:url('{{ $p['photo'] }}')" @endif aria-hidden="true">@unless ($p['photo'])<x-filament::icon icon="heroicon-o-building-office-2" />@endunless</span>
                    <span class="wq-node-title"><strong>{{ $p['name'] }}</strong><small>{{ $p['counts']['leased'] }} عقد ساري من {{ $p['counts']['total'] }} وحدة@if ($p['counts']['soon'])، و{{ $p['counts']['soon'] }} أُشعر بعدم التجديد@endif</small></span>
                    <span class="wq-node-occ" title="نسبة الإشغال"><span class="wq-bar"><span style="--p: {{ intdiv($p['counts']['occupancy_bp'], 100) }}%"></span></span><b>{{ intdiv($p['counts']['occupancy_bp'], 100) }}%</b></span>
                    <span></span>
                </summary>
                @foreach ($p['buildings'] as $b)
                    @foreach ($b['floors'] as $f)
                        @foreach ($f['units'] as $u)
                            <div class="wq-leaserow">
                                <span class="wq-unitrow-code wq-s-{{ $u['status'] }}" title="{{ $f['name'] }}">{{ $u['code'] }}</span>
                                @if ($l = $u['lease'])
                                    <span class="wq-leaserow-who">
                                        <a href="{{ $base }}/leases/{{ $l['id'] }}"><strong>{{ $l['tenant'] }}</strong></a>
                                        <small>{{ $l['phone'] ?: 'لا جوال مسجل' }}@if ($l['ejar'])، عقد إيجار {{ $l['ejar'] }}@endif</small>
                                    </span>
                                    <span class="wq-leaserow-term">
                                        <span class="wq-term-dates"><span>{{ $l['starts_on']->format('Y-m-d') }}</span><span>{{ $l['ends_on']->format('Y-m-d') }}</span></span>
                                        <span class="wq-bar {{ $l['days_left'] !== null && $l['days_left'] <= 60 ? 'wq-near' : '' }} {{ $l['days_left'] !== null && $l['days_left'] <= 15 ? 'wq-urgent' : '' }}"><span style="--p: {{ $l['elapsed_pct'] }}%"></span></span>
                                        <small>{{ $l['future'] ? 'يبدأ بعد ' . $days((int) $l['starts_on']->diffInDays(now()->startOfDay(), true)) : ($l['days_left'] >= 0 ? 'باقٍ ' . $days($l['days_left']) : 'انتهى') }}</small>
                                    </span>
                                    <span class="wq-leaserow-status"><span class="wq-chip wq-st-{{ $l['status'] }}">{{ $statusAr[$l['status']] ?? $l['status'] }}</span></span>
                                    <span class="wq-unitrow-rent">{{ $money($u['rent']) }}<small>سنوياً</small></span>
                                @else
                                    <span class="wq-leaserow-who wq-muted">شاغرة، لا عقد ساري</span>
                                    <span class="wq-leaserow-term"></span>
                                    <span class="wq-leaserow-status"><a class="wq-link" href="{{ $base }}/lease-offers/create">عرض تأجير</a></span>
                                    <span class="wq-unitrow-rent wq-asking">{{ $money($u['asking']) }}<small>السعر المطلوب</small></span>
                                @endif
                            </div>
                            @if (count($u['history']))
                                <details class="wq-history">
                                    <summary>{{ count($u['history']) === 1 ? 'عقد سابق' : count($u['history']) . ' عقود سابقة' }} للوحدة {{ $u['code'] }}</summary>
                                    <ul>
                                        @foreach ($u['history'] as $h)
                                            <li><a href="{{ $base }}/leases/{{ $h['id'] }}">{{ $h['tenant'] }}</a><span>{{ $h['starts_on']->format('Y-m-d') }} — {{ $h['ends_on']->format('Y-m-d') }}</span><span class="wq-chip wq-st-{{ $h['status'] }}">{{ $statusAr[$h['status']] ?? $h['status'] }}</span></li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        @endforeach
                    @endforeach
                @endforeach
            </details>
        @empty
            <section class="wq-panel wq-empty">
                @if ($q !== '')
                    <p>لا عقد ولا مستأجر يطابق «{{ $q }}».</p>
                    <button type="button" class="wq-btn-quiet" wire:click="$set('q', '')">مسح البحث</button>
                @else
                    <p>لا عقود بعد. ابدأ بعرض تأجير، أو انقل عقودك القائمة من ملف إيجار.</p>
                    <a class="wq-btn" href="{{ $base }}/quick-migration">الانتقال السريع</a>
                @endif
            </section>
        @endforelse
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
