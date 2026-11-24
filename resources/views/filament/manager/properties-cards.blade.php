@php
    $cards = $this->cards();
    $base = filament()->getCurrentPanel()->getUrl();
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money($m);
    $order = ['paid' => 'مؤجرة ومسددة', 'late' => 'عليها متأخرات', 'soon' => 'ستشغر', 'maint' => 'تحت الصيانة', 'vacant' => 'شاغرة'];
@endphp
<x-filament-panels::page class="wq-page">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>العقارات</h1>
            <p>كل عقار بصورته وملّاكه وحال وحداته اليوم، وما يدرّه من إيجار سنوي.</p>
        </div>
        <div class="wq-pager">
            <div class="wq-seg" role="group" aria-label="طريقة العرض">
                <button type="button" wire:click="$set('mode', 'cards')" @class(['is-on' => $mode === 'cards'])>البطاقات</button>
                <button type="button" wire:click="$set('mode', 'table')" @class(['is-on' => $mode === 'table'])>الجدول</button>
            </div>
            @if (auth()->user()->hasPermission('properties.create'))<a class="wq-btn" href="{{ $base }}/add-building">إضافة مبنى</a>@endif
        </div>
    </header>

    @if ($mode === 'cards')
        <div class="wq-treebar">
            <label class="wq-search"><x-filament::icon icon="heroicon-m-magnifying-glass" class="wq-search-ic" /><input type="search" wire:model.live.debounce.400ms="q" placeholder="ابحث باسم العقار أو المدينة أو رقم الصك" aria-label="بحث في العقارات"></label>
            <div class="wq-pager">
                <label class="wq-idband-pick"><span>الترتيب</span><select class="wq-select" wire:model.live="sort">@foreach (\App\Filament\Manager\Resources\Properties\Pages\ListProperties::SORTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <div class="wq-seg" role="group" aria-label="التجميع">
                    <button type="button" wire:click="$set('group', 'none')" @class(['is-on' => $group === 'none'])>بلا تجميع</button>
                    <button type="button" wire:click="$set('group', 'city')" @class(['is-on' => $group === 'city'])>حسب المدينة</button>
                </div>
            </div>
        </div>
        @forelse ($this->grouped($cards) as $city => $groupCards)
        @if ($city !== '')<h2 class="wq-citygroup">{{ $city }} <small>{{ count($groupCards) }} {{ count($groupCards) === 1 ? 'عقار' : 'عقارات' }}</small></h2>@endif
        <div class="wq-props">
            @foreach ($groupCards as $c)
                <article class="wq-prop">
                    <a class="wq-prop-photo" href="{{ $base }}/properties/{{ $c['id'] }}" @if ($c['photo']) style="background-image:url('{{ $c['photo'] }}')" @endif>
                        @unless ($c['photo'])<x-filament::icon icon="heroicon-o-building-office-2" class="wq-prop-ph" /><span>أضف صورة من «تعديل»</span>@endunless
                        <span class="wq-prop-occ">{{ intdiv($c['counts']['occupancy_bp'], 100) }}% مشغول</span>
                    </a>
                    <div class="wq-prop-body">
                        <h2><a href="{{ $base }}/properties/{{ $c['id'] }}">{{ $c['name'] }}</a></h2>
                        <p class="wq-prop-meta">{{ $c['city'] ?: 'بلا مدينة' }}@if ($c['deed'])، صك {{ $c['deed'] }}@endif</p>
                        <p class="wq-prop-owners">{{ count($c['owners']) ? implode('، ', $c['owners']) : 'لم يُربط مالك' }}</p>
                        <div class="wq-prop-units" role="img" aria-label="حال الوحدات">
                            @foreach ($order as $k => $l)
                                @if ($c['counts'][$k] ?? 0)<span class="wq-s-{{ $k }}" style="flex: {{ $c['counts'][$k] }}" title="{{ $l }}: {{ $c['counts'][$k] }}"></span>@endif
                            @endforeach
                        </div>
                        <dl class="wq-prop-figs">
                            <div><dt>الوحدات</dt><dd>{{ $c['counts']['total'] }}</dd></div>
                            <div><dt>شاغرة</dt><dd>{{ $c['counts']['vacant'] }}</dd></div>
                            <div><dt>الإيجار السنوي</dt><dd>{{ $money($c['roll']) }}</dd></div>
                            <div><dt>أوامر عمل مفتوحة</dt><dd>{{ $c['open_wo'] }}</dd></div>
                        </dl>
                        <nav class="wq-prop-links">
                            <a href="{{ $base }}/properties/{{ $c['id'] }}">الشجرة والتفاصيل</a>
                            <a href="{{ $base }}/leasable-units">الوحدات</a>
                            <a href="{{ $base }}/building-contents">المحتوى والجرد</a>
                            <a href="{{ $base }}/properties/{{ $c['id'] }}/edit">تعديل</a>
                        </nav>
                    </div>
                </article>
            @endforeach
        </div>
        @empty
        @endforelse
        @if (! count($cards))
            <section class="wq-panel wq-empty">
                @if ($q !== '')<p>لا عقار يطابق «{{ $q }}».</p>@else<p>لا عقارات بعد. المعالج يبني المبنى بأدواره ووحداته وأسعاره في دقيقة.</p><a class="wq-btn" href="{{ $base }}/add-building">إضافة مبنى</a>@endif
            </section>
        @else
            @include('filament.manager.portfolio-map', ['cards' => $cards, 'base' => $base])
        @endif
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
