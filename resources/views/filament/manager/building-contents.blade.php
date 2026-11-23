@php
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money((int) $m);
    $pct = fn ($bp) => intdiv((int) $bp, 100);
    $months = function (?int $m) {
        if ($m === null) return '—';
        if ($m <= 0) return 'انتهى';
        $y = intdiv($m, 12); $r = $m % 12;
        return trim(($y ? $y . ' ' . ($y === 1 ? 'سنة' : ($y === 2 ? 'سنتان' : ($y <= 10 ? 'سنوات' : 'سنة'))) : '') . ($y && $r ? ' و' : '') . ($r ? $r . ' ' . ($r === 1 ? 'شهر' : ($r === 2 ? 'شهران' : ($r <= 10 ? 'أشهر' : 'شهراً'))) : ''));
    };
    // compact amount for narrow chart labels: 81,000 → "81 ألف", 1,250,000 → "1.25 مليون" (riyals, display only)
    $short = function (int $minor) {
        $r = intdiv($minor, 100);
        return $r >= 1000000 ? rtrim(rtrim(number_format($r / 1000000, 2), '0'), '.') . ' مليون' : ($r >= 1000 ? rtrim(rtrim(number_format($r / 1000, 1), '0'), '.') . ' ألف' : number_format($r));
    };
    $month = fn ($d) => \IntlDateFormatter::formatObject($d, 'MMMM y', 'ar@calendar=gregorian;numbers=latn');
    $stateLabel =['ok' => 'في الخدمة', 'near' => 'يقترب من نهاية عمره', 'ended' => 'تجاوز عمره', 'incomplete' => 'بيانات ناقصة'];
@endphp
<x-filament-panels::page class="wq-page">
    @if (! $b)
        <section class="wq-panel wq-empty">
            <p>لا مبانٍ بعد. أضف مبنى بالمعالج، وتُنشأ أصول كل وحدة من قالب نوعها.</p>
            <a class="wq-btn" href="{{ $base }}/add-building">إضافة مبنى</a>
        </section>
    @else
        @php($t = $data['totals'])
        {{-- Identity band: which building, and the one sentence that matters --}}
        <header class="wq-idband">
            <div class="wq-idband-photo" @if ($b->property->photoUrl()) style="background-image:url('{{ $b->property->photoUrl() }}')" @endif aria-hidden="true">
                @unless ($b->property->photoUrl())<x-filament::icon icon="heroicon-o-building-office-2" class="wq-idband-ph" />@endunless
            </div>
            <div class="wq-idband-text">
                <h1>محتوى {{ $b->property->name_ar }}</h1>
                <p>
                    @if ($t['count'])
                        {{ $t['count'] }} أصلاً تساوي اليوم {{ $money($t['book']) }} من أصل {{ $money($t['cost']) }} دُفعت فيها.
                        @if ($t['near'] + $t['ended']) {{ $t['near'] + $t['ended'] }} منها {{ $t['ended'] ? 'تجاوز عمره أو ' : '' }}يقترب من الاستبدال. @endif
                    @else
                        لم تُسجَّل أصول لهذا المبنى بعد.
                    @endif
                </p>
            </div>
            @if (count($buildings) > 1)
                <label class="wq-idband-pick">
                    <span>المبنى</span>
                    <select class="wq-select" wire:model.live="building">
                        @foreach ($buildings as $o)<option value="{{ $o['id'] }}" @selected($o['id'] === $b->id)>{{ $o['name'] }}</option>@endforeach
                    </select>
                </label>
            @endif
        </header>

        {{-- Ledger strip: figures side by side, divided by hairlines --}}
        <dl class="wq-ledger">
            <div><dt>القيمة الحالية</dt><dd>{{ $money($t['book']) }}</dd><small>بعد إهلاك {{ $pct($t['pct_bp']) }}%</small></div>
            <div><dt>الإهلاك السنوي</dt><dd>{{ $money($t['yearly']) }}</dd><small>ما تفقده الأصول من قيمتها كل سنة</small></div>
            <div><dt>صُرف على الصيانة هذا العام</dt><dd>{{ $money($t['spend_year']) }}</dd><small>منذ البداية {{ $money($t['spend_all']) }}</small></div>
            <div><dt>متوسط الحالة</dt><dd>{{ $t['condition_avg10'] !== null ? number_format($t['condition_avg10'] / 10, 1) : '—' }}<span class="wq-ledger-of"> من 5</span></dd><small>من آخر تقييم لكل أصل</small></div>
            <div><dt>اكتمال جرد التأسيس</dt><dd>{{ $pct($data['inventory']['bp']) }}%</dd><small>{{ $data['inventory']['done'] }} من {{ $data['inventory']['units'] }} وحدة</small></div>
        </dl>

        <div class="wq-two">
            {{-- Life ribbons by category: the one bold element --}}
            <section class="wq-panel" aria-labelledby="cat-h">
                <header class="wq-panel-head"><h2 id="cat-h">ما يحتويه المبنى</h2><span class="wq-panel-meta">القيمة المتبقية من التكلفة لكل فئة</span></header>
                @forelse ($data['categories'] as $c)
                    @php($left = $c['cost'] ? intdiv($c['book'] * 100, $c['cost']) : 0)
                    <div class="wq-ribbon-row">
                        <div class="wq-ribbon-name"><strong>{{ $c['label'] }}</strong><small>{{ $c['count'] }} {{ $c['count'] === 1 ? 'أصل' : ($c['count'] === 2 ? 'أصلان' : ($c['count'] <= 10 ? 'أصول' : 'أصلاً')) }}</small></div>
                        <div class="wq-ribbon {{ $left <= 15 ? 'is-low' : ($left <= 40 ? 'is-mid' : '') }}" role="img" aria-label="متبقٍ {{ $left }}% من القيمة">
                            <span style="--p: {{ $left }}%"></span>
                        </div>
                        <div class="wq-ribbon-num"><strong>{{ $money($c['book']) }}</strong><small>{{ $left }}% من {{ $money($c['cost']) }}</small></div>
                    </div>
                @empty
                    <p class="wq-calm">لا أصول بعد. أضفها من «الأصول»، أو يُنشئها المعالج من قالب نوع الوحدة.</p>
                @endforelse
            </section>

            {{-- Replacement budget --}}
            <section class="wq-panel" aria-labelledby="bud-h">
                <header class="wq-panel-head"><h2 id="bud-h">ميزانية الاستبدال المقترحة</h2><span class="wq-panel-meta">بتكلفة الشراء، في سنة خروج كل أصل من الخدمة</span></header>
                @php($max = max(1, max($data['budget'])))
                <ol class="wq-years">
                    @foreach ($data['budget'] as $year => $amount)
                        <li>
                            <span class="wq-year-bar" style="--h: {{ max(2, intdiv($amount * 100, $max)) }}%" @if (! $amount) data-zero @endif></span>
                            <strong title="{{ $money($amount) }}">{{ $amount ? $short($amount) : '—' }}</strong>
                            <small>{{ $year }}</small>
                        </li>
                    @endforeach
                </ol>
                @if ($t['incomplete'])
                    <p class="wq-note">{{ $t['incomplete'] }} {{ $t['incomplete'] === 1 ? 'أصل ينقصه' : 'أصول ينقصها' }} التكلفة أو تاريخ التركيب أو أساس الإهلاك، فلم {{ $t['incomplete'] === 1 ? 'يدخل' : 'تدخل' }} في الحساب. <a href="{{ $base }}/assets">أكمل بياناتها</a></p>
                @endif
            </section>
        </div>

        @if (count($data['near']))
            <section class="wq-panel" aria-labelledby="near-h">
                <header class="wq-panel-head"><h2 id="near-h">يقترب من الاستبدال</h2><span class="wq-panel-meta">خلال {{ \App\Maintenance\AssetValuation::NEAR_END_MONTHS }} شهراً أو تجاوز عمره</span></header>
                <ul class="wq-nearlist">
                    @foreach (array_slice($data['near'], 0, 8) as $r)
                        <li class="{{ $r['v']['state'] === 'ended' ? 'is-ended' : '' }}">
                            <span class="wq-near-what"><strong>{{ $r['asset']->name_ar }}</strong><small>{{ $r['where'] }}</small></span>
                            <span class="wq-near-when">{{ $r['v']['state'] === 'ended' ? 'تجاوز عمره منذ ' . $month($r['v']['ends_on']) : 'يخرج من الخدمة ' . $month($r['v']['ends_on']) }}</span>
                            <span class="wq-near-cost">{{ $money($r['cost']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="wq-panel" aria-labelledby="all-h">
            <header class="wq-panel-head">
                <h2 id="all-h">كل الأصول</h2>
                <div class="wq-seg" role="group" aria-label="تصفية">
                    @foreach (['all' => 'الكل', 'near' => 'يقترب', 'ended' => 'تجاوز عمره', 'incomplete' => 'ناقص البيانات'] as $k => $label)
                        <button type="button" wire:click="$set('filter', '{{ $k }}')" @class(['is-on' => $filter === $k]) aria-pressed="{{ $filter === $k ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </header>
            <div class="wq-table-wrap">
                <table class="wq-table">
                    <thead><tr><th>الأصل</th><th>الموقع</th><th>الحالة</th><th>التكلفة</th><th>القيمة الحالية</th><th class="wq-col-life">العمر المستهلك</th><th>المتبقي</th><th>صُرف عليه</th></tr></thead>
                    <tbody>
                        @forelse (array_slice($data['rows'], 0, $limit) as $r)
                            @php($v = $r['v'])
                            <tr>
                                <td><a href="{{ $base }}/assets/{{ $r['asset']->id }}/edit">{{ $r['asset']->name_ar }}</a><small class="wq-sub">{{ \App\Maintenance\AssetTaxonomy::CATEGORIES[$r['asset']->category]['ar'] ?? '' }}</small></td>
                                <td>{{ $r['where'] }}</td>
                                <td>{{ $r['asset']->condition ? $r['asset']->condition . ' من 5' : '—' }}</td>
                                <td>{{ $r['cost'] ? $money($r['cost']) : '—' }}</td>
                                <td>{{ $v['book'] !== null ? $money($v['book']) : '—' }}</td>
                                <td class="wq-col-life">
                                    @if ($v['state'] === 'incomplete')
                                        <span class="wq-chip wq-s-vacant">{{ $stateLabel['incomplete'] }}</span>
                                    @else
                                        <span class="wq-bar {{ $v['state'] === 'ended' ? 'wq-urgent' : ($v['state'] === 'near' ? 'wq-near' : '') }}" style="--p: {{ $pct($v['pct_bp']) }}%"><span></span></span>
                                        <span class="wq-bar-text">{{ $pct($v['pct_bp']) }}%</span>
                                    @endif
                                </td>
                                <td>{{ $v['state'] === 'incomplete' ? '—' : $months($v['remaining_months']) }}</td>
                                <td>{{ $r['spend'] ? $money($r['spend']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="wq-calm">لا أصول في هذا التصنيف.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (count($data['rows']) > $limit)
                <div class="wq-more"><button type="button" class="wq-btn-quiet" wire:click="$set('limit', 1000)">عرض كل الأصول ({{ count($data['rows']) }})</button></div>
            @endif
        </section>
    @endif
</x-filament-panels::page>
