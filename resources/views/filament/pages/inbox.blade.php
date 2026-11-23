@php
    $total = $counts['approvals'] + $counts['reports'] + $counts['arrears'];
    $first = explode(' ', trim($user->name))[0];
@endphp
<x-filament-panels::page class="wq-page wq-inbox">
    {{-- The desk: one sentence for the whole box, then three trays --}}
    <header class="wq-desk">
        <div class="wq-desk-text">
            <h1>صندوق المهام</h1>
            <p>
                @if ($total === 0)
                    لا شيء ينتظرك يا {{ $first }}. ما يصل من اعتمادات وبلاغات ومتأخرات يظهر هنا أولاً بأول.
                @else
                    {{ $total === 1 ? 'أمر واحد ينتظرك' : ($total === 2 ? 'أمران ينتظرانك' : $total . ($total <= 10 ? ' أمور تنتظرك' : ' أمراً ينتظرك')) }}. ابدأ بالطارئ، ثم ما ينتظر قرارك.
                @endif
            </p>
        </div>
        <nav class="wq-trays" aria-label="أقسام الصندوق">
            <a href="#tray-approvals" class="wq-tray {{ $counts['approvals'] ? 'is-full' : '' }}" style="--tone: var(--status-soon-fg)"><b>{{ $counts['approvals'] }}</b><span>بانتظار قرارك</span></a>
            <a href="#tray-reports" class="wq-tray {{ $counts['reports'] ? 'is-full' : '' }}" style="--tone: var(--status-maint-fg)"><b>{{ $counts['reports'] }}</b><span>بلاغات صيانة</span></a>
            <a href="#tray-arrears" class="wq-tray {{ $counts['arrears'] ? 'is-full' : '' }}" style="--tone: var(--status-late-fg)"><b>{{ $counts['arrears'] }}</b><span>متأخرات مصعّدة</span></a>
        </nav>
    </header>

    <div class="wq-lanes">
        <section class="wq-lane" id="tray-approvals" aria-labelledby="l1" style="--tone: var(--status-soon-fg)">
            <h2 id="l1">بانتظار قرارك <span>{{ $counts['approvals'] }}</span></h2>
            @forelse ($approvals as $a)
                <article class="wq-ticket">
                    <span class="wq-ticket-kind">{{ $a['kind'] }}</span>
                    <strong>{{ $a['title'] }}</strong>
                    <small>{{ $a['meta'] }}@if ($a['ago'])، {{ $a['ago'] }}@endif</small>
                    <a class="wq-ticket-cta" href="{{ $a['url'] }}">{{ $a['cta'] }}</a>
                </article>
            @empty
                <p class="wq-lane-empty">لا عروض تأجير ولا قيود حساسة تنتظر اعتمادك.</p>
            @endforelse
        </section>

        <section class="wq-lane" id="tray-reports" aria-labelledby="l2" style="--tone: var(--status-maint-fg)">
            <h2 id="l2">بلاغات صيانة جديدة <span>{{ $counts['reports'] }}</span></h2>
            @forelse ($reports as $r)
                <article class="wq-ticket {{ $r['priority'] === 'emergency' ? 'is-urgent' : '' }}">
                    <span class="wq-ticket-kind">{{ $r['type'] }}@unless ($r['priority'] === 'emergency' && $r['type'] === 'طارئة')، {{ $r['priority_ar'] }}@endunless</span>
                    <strong>{{ $r['title'] }}</strong>
                    <small>{{ $r['property'] }}، {{ $r['number'] }}@if ($r['ago'])، {{ $r['ago'] }}@endif</small>
                    @if ($r['awaiting_owner'])<span class="wq-chip wq-s-soon">بانتظار موافقة المالك</span>@endif
                    <a class="wq-ticket-cta" href="{{ $r['url'] }}">{{ $r['priority'] ? 'افتح البلاغ' : 'افرز وحدد الأولوية' }}</a>
                </article>
            @empty
                <p class="wq-lane-empty">لا بلاغات جديدة. البلاغ الذي يصل من مستأجر أو فني يظهر هنا قبل فرزه.</p>
            @endforelse
        </section>

        <section class="wq-lane" id="tray-arrears" aria-labelledby="l3" style="--tone: var(--status-late-fg)">
            <h2 id="l3">متأخرات مصعّدة إليك <span>{{ $counts['arrears'] }}</span></h2>
            @forelse ($arrears as $a)
                <article class="wq-ticket">
                    <span class="wq-ticket-kind">دفعة متأخرة</span>
                    <strong>{{ $a['body'] }}</strong>
                    <a class="wq-ticket-cta" href="{{ $base }}/invoices">راجع الفواتير</a>
                </article>
            @empty
                <p class="wq-lane-empty">لا متأخرات صُعّدت إليك. تُصعَّد الدفعة إذا مرّ أسبوع على استحقاقها دون سداد.</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
