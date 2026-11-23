@php
    $tree = $this->tree();
    $money = fn ($m) => $m === null ? '—' : \App\Support\Dates\NumberFormatter::money($m);
    $statusLabels = ['paid' => 'مؤجرة ومسددة', 'late' => 'عليها متأخرات', 'vacant' => 'شاغرة', 'maint' => 'تحت الصيانة', 'soon' => 'ستشغر'];
    $types = collect(\App\Properties\UnitTypes::TYPES)->map(fn ($t) => $t['ar']);
    $base = filament()->getCurrentPanel()->getUrl();
@endphp
<x-filament-panels::page class="wq-page">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>الوحدات التأجيرية</h1>
            <p>كل عقار ثم مبانيه وأدواره ووحداته، وحالة كل وحدة اليوم ومن يسكنها.</p>
        </div>
        <div class="wq-seg" role="group" aria-label="طريقة العرض">
            <button type="button" wire:click="$set('mode', 'tree')" @class(['is-on' => $mode === 'tree']) aria-pressed="{{ $mode === 'tree' ? 'true' : 'false' }}">الشجرة</button>
            <button type="button" wire:click="$set('mode', 'table')" @class(['is-on' => $mode === 'table']) aria-pressed="{{ $mode === 'table' ? 'true' : 'false' }}">إدارة الوحدات</button>
        </div>
    </header>

    @if ($mode === 'tree')
        <div class="wq-treebar">
            <label class="wq-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="wq-search-ic" />
                <input type="search" wire:model.live.debounce.400ms="q" placeholder="ابحث برقم الوحدة أو اسم المستأجر أو جواله أو رقم عقد إيجار" aria-label="بحث في الوحدات">
            </label>
            <ul class="wq-legend wq-legend-inline">
                @foreach ($statusLabels as $k => $label)<li><span class="wq-dot wq-s-{{ $k }}"></span>{{ $label }}</li>@endforeach
            </ul>
        </div>

        @forelse ($tree as $p)
            <details class="wq-node" open>
                <summary class="wq-node-head">
                    <span class="wq-node-photo" @if ($p['photo']) style="background-image:url('{{ $p['photo'] }}')" @endif aria-hidden="true">@unless ($p['photo'])<x-filament::icon icon="heroicon-o-building-office-2" />@endunless</span>
                    <span class="wq-node-title"><strong>{{ $p['name'] }}</strong><small>{{ $p['counts']['total'] }} وحدة، منها {{ $p['counts']['leased'] }} مؤجرة و{{ $p['counts']['vacant'] }} شاغرة</small></span>
                    <span class="wq-node-occ" title="نسبة الإشغال"><span class="wq-bar"><span style="--p: {{ intdiv($p['counts']['occupancy_bp'], 100) }}%"></span></span><b>{{ intdiv($p['counts']['occupancy_bp'], 100) }}%</b></span>
                    <a class="wq-node-link" href="{{ $base }}/properties/{{ $p['id'] }}">صفحة العقار</a>
                </summary>
                @foreach ($p['buildings'] as $b)
                    @if (count($p['buildings']) > 1)<h3 class="wq-node-sub">{{ $b['name'] }}</h3>@endif
                    @foreach ($b['floors'] as $f)
                        <div class="wq-floorrow">
                            <span class="wq-floorrow-name">{{ $f['name'] }}</span>
                            <ul class="wq-unitlist">
                                @foreach ($f['units'] as $u)
                                    <li class="wq-unitrow">
                                        <span class="wq-unitrow-code wq-s-{{ $u['status'] }}">{{ $u['code'] }}</span>
                                        <span class="wq-unitrow-main">
                                            <strong>{{ $types[$u['type']] ?? $u['name'] }}@if ($u['composite']) <small class="wq-tag">مدمجة</small>@endif</strong>
                                            <small>{{ $statusLabels[$u['status']] }}@if ($u['lease'] && $u['lease']['future'])، وعقد يبدأ {{ $u['lease']['starts_on']->format('Y-m-d') }}@endif</small>
                                        </span>
                                        <span class="wq-unitrow-tenant">
                                            @if ($u['lease'])
                                                <a href="{{ $base }}/leases/{{ $u['lease']['id'] }}">{{ $u['lease']['tenant'] }}</a>
                                                <small>حتى {{ $u['lease']['ends_on']->format('Y-m-d') }}</small>
                                            @else
                                                <span class="wq-muted">بلا مستأجر</span>
                                            @endif
                                        </span>
                                        @if ($u['rent'] !== null)
                                            <span class="wq-unitrow-rent">{{ $money($u['rent']) }}<small>الإيجار السنوي</small></span>
                                        @elseif ($u['asking'] !== null)
                                            <span class="wq-unitrow-rent wq-asking">{{ $money($u['asking']) }}<small>السعر المطلوب سنوياً</small></span>
                                        @else
                                            <span class="wq-unitrow-rent wq-muted">—<small>لا سعر بعد</small></span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @endforeach
            </details>
        @empty
            <section class="wq-panel wq-empty">
                @if ($q !== '')
                    <p>لا وحدة ولا مستأجر يطابق «{{ $q }}».</p>
                    <button type="button" class="wq-btn-quiet" wire:click="$set('q', '')">مسح البحث</button>
                @else
                    <p>لا عقارات بعد. أضف مبنى بالمعالج وتُنشأ أدواره ووحداته.</p>
                    <a class="wq-btn" href="{{ $base }}/add-building">إضافة مبنى</a>
                @endif
            </section>
        @endforelse
    @else
        <p class="wq-help">من هنا تضبط السعر والخصائص، و<strong>تغيّر النوع</strong> عند تصحيح تصنيف وحدة (شقة صارت مكتباً)، و<strong>تدمج</strong> وحدتين متجاورتين لتؤجرا بعقد واحد، و<strong>تقسّم</strong> وحدة كبيرة لتؤجر أجزاؤها بعقود منفصلة. الوحدات الأصلية تبقى في السجل ولا تُحذف.</p>
        {{ $this->table }}
    @endif
</x-filament-panels::page>
