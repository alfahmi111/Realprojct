@php
    $labels = ['leased' => 'مؤجرة', 'vacant' => 'شاغرة', 'held' => 'محجوزة مبدئياً', 'maintenance' => 'تحت الصيانة'];
    $pctAt = fn (int $i) => round($i * 100 / $spanDays, 4);
@endphp
<x-filament-panels::page class="wq-page wq-avail">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>تقويم الإتاحة</h1>
            @php($fmt = fn ($d) => \IntlDateFormatter::formatObject($d, 'd MMMM y', 'ar@calendar=gregorian;numbers=latn'))
            <p>كل وحدة على خط زمني: متى هي مؤجرة ولمن، ومتى تفرغ، وما المتاح للتأجير من {{ $fmt($start) }} إلى {{ $fmt($end) }}.</p>
        </div>
    </header>

    <div class="wq-avail-bar">
        @if (count($properties) > 1)
            <label class="wq-idband-pick"><span>العقار</span>
                <select class="wq-select" wire:model.live="property">@foreach ($properties as $id => $name)<option value="{{ $id }}" @selected($id == $pid)>{{ $name }}</option>@endforeach</select>
            </label>
        @endif
        <div class="wq-seg" role="group" aria-label="المدة">
            @foreach (\App\Filament\Manager\Pages\AvailabilityPage::SPANS as $d)
                <button type="button" wire:click="$set('span', {{ $d }})" @class(['is-on' => $spanDays === $d]) aria-pressed="{{ $spanDays === $d ? 'true' : 'false' }}">{{ $d }} يوماً</button>
            @endforeach
        </div>
        <div class="wq-pager">
            <button type="button" class="wq-btn-quiet" wire:click="shift(-1)" aria-label="الفترة السابقة">السابق</button>
            <button type="button" class="wq-btn-quiet" wire:click="today">اليوم</button>
            <button type="button" class="wq-btn-quiet" wire:click="shift(1)" aria-label="الفترة التالية">التالي</button>
        </div>
    </div>

    <section class="wq-panel wq-avail-tl"aria-label="الخط الزمني للوحدات">
        <div class="wq-tl-head">
            <span class="wq-tl-lab">الوحدة</span>
            <div class="wq-tl-axis">
                @foreach ($months as $mth)<span class="wq-tl-month" style="inset-inline-start: {{ $pctAt($mth['at']) }}%">{{ $mth['label'] }}</span>@endforeach
                @if ($todayAt !== null)<span class="wq-tl-today-tag" style="inset-inline-start: {{ $pctAt($todayAt) }}%">اليوم</span>@endif
            </div>
        </div>
        @forelse ($lanes as $lane)
            <div class="wq-tl-row">
                <span class="wq-tl-lab"><strong>{{ $lane['code'] }}</strong>@if ($lane['vacating'])<small class="wq-tl-vac">تفرغ خلال {{ $lane['vacating']['in_days'] }} يوماً</small>@endif</span>
                <div class="wq-tl-lane">
                    @foreach ($months as $mth)<span class="wq-tl-grid" style="inset-inline-start: {{ $pctAt($mth['at']) }}%"></span>@endforeach
                    @foreach ($lane['segs'] as $s)
                        @php($w = $pctAt($s['len']))
                        @if ($s['state'] === 'vacant')
                            <a class="wq-tl-seg is-vacant" href="{{ $base }}/lease-offers/create" style="inset-inline-start: {{ $pctAt($s['at']) }}%; width: {{ $w }}%" title="{{ $lane['code'] }} شاغرة من {{ $s['from'] }} إلى {{ $s['to'] }}، أنشئ عرض تأجير">@if ($w > 9)<span>شاغرة {{ $s['len'] }} يوماً</span>@endif</a>
                        @else
                            <span class="wq-tl-seg is-{{ $s['state'] }}" style="inset-inline-start: {{ $pctAt($s['at']) }}%; width: {{ $w }}%" title="{{ $lane['code'] }} {{ $labels[$s['state']] }} من {{ $s['from'] }} إلى {{ $s['to'] }}{{ $s['label'] ? '، ' . $s['label'] : '' }}">@if ($w > 9)<span>{{ $s['label'] ?? $labels[$s['state']] }}</span>@endif</span>
                        @endif
                    @endforeach
                    @if ($todayAt !== null)<span class="wq-tl-today" style="inset-inline-start: {{ $pctAt($todayAt) }}%"></span>@endif
                </div>
            </div>
        @empty
            <p class="wq-calm">لا وحدات في هذا العقار بعد.</p>
        @endforelse
        <ul class="wq-legend">
            @foreach ($labels as $k => $l)<li><span class="wq-dot wq-tl-key is-{{ $k }}"></span>{{ $l }} <b>{{ $share[$k] }}%</b></li>@endforeach
            <li class="wq-tl-hint">اضغط على أي فترة شاغرة لإنشاء عرض تأجير.</li>
        </ul>
    </section>
</x-filament-panels::page>
