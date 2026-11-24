@php
    $dates = app(\App\Support\Dates\DateService::class);
    $now = \Carbon\CarbonImmutable::now();
    $tz = 'Asia/Riyadh';
    $day = \IntlDateFormatter::formatObject($now->setTimezone($tz), 'EEEE', 'ar_SA');
    $hijri = $dates->format($now, 'hijri', $tz);
    $greg = $dates->format($now, 'gregorian', $tz);
    // Each door says who it is for and what waits behind it (true statements about what is built).
    $doors = [
        'manager' => ['door' => 'دخول فريق المكتب', 'points' => [
            ['heroicon-o-document-chart-bar', 'كشف شهري لكل مالك: المحصّل والأتعاب والصافي'],
            ['heroicon-o-calendar-days', 'التاريخ الهجري بتقويم أم القرى في كل شاشة ومستند'],
            ['heroicon-o-shield-check', 'تحقق بخطوتين وموافقة مزدوجة على صرف أموال الملاك'],
        ]],
        'owner' => ['door' => 'دخول الملاك', 'points' => [
            ['heroicon-o-banknotes', 'كشف حسابك الشهري بكل خصم ونسبته'],
            ['heroicon-o-check-badge', 'وافق على الصيانة التي تتجاوز السقف من جوالك'],
            ['heroicon-o-shield-check', 'حسابك محمي بالتحقق بخطوتين'],
        ]],
        'tenant' => ['door' => 'دخول المستأجرين', 'points' => [
            ['heroicon-o-home-modern', 'عقدك ومواعيده وفواتيرك في مكان واحد'],
            ['heroicon-o-wrench-screwdriver', 'بلّغ عن عطل بصورة وتابع إصلاحه'],
            ['heroicon-o-bell-alert', 'تذكير قبل موعد كل دفعة'],
        ]],
        'technician' => ['door' => 'دخول الفنيين', 'points' => [
            ['heroicon-o-clipboard-document-check', 'مهامك مرتبة بالأولوية'],
            ['heroicon-o-signal-slash', 'تعمل دون اتصال وتُرسل عند عودة الشبكة'],
            ['heroicon-o-camera', 'صوّر قبل وبعد وأغلق المهمة'],
        ]],
        'platform' => ['door' => 'إدارة المنصة', 'points' => [
            ['heroicon-o-building-storefront', 'المشتركون واشتراكاتهم ومهلهم'],
            ['heroicon-o-lifebuoy', 'دعم حسابات المكاتب بسجل تدقيق'],
            ['heroicon-o-rocket-launch', 'جاهزية الإطلاق في لوحة واحدة'],
        ]],
    ];
    $panelId = filament()->getCurrentPanel()?->getId() ?? 'manager';
    $door = $doors[$panelId] ?? $doors['manager'];
    // A small façade: windows in the five fixed unit-status colours (the signature of the manager's home).
    $windows = ['paid','paid','late','paid','vacant', 'paid','maint','paid','paid','paid', 'soon','paid','paid','vacant','paid', 'paid','paid','paid','late','paid'];
@endphp
{{-- Identity panel beside the sign-in card (layout of the owner's design; no third-party logos). --}}
<aside class="wq-auth-aside" aria-label="وِثاق">
    <div class="wq-auth-brand">
        {{-- The stacked logo (Kufic wordmark over Withaq), white on the identity panel; its aria-label names the platform. --}}
        <h1 class="wq-auth-title"><span class="wq-auth-logo">{!! file_get_contents(public_path('brand/withaq-stacked.svg')) !!}</span></h1>
        <div class="wq-auth-kicker">منصة إدارة الأملاك والأصول العقارية</div>
    </div>

    <span class="wq-auth-door">{{ $door['door'] }}</span>

    <figure class="wq-auth-facade" aria-hidden="true">
        <div class="wq-facade-roof"></div>
        <div class="wq-facade-grid">
            @foreach ($windows as $i => $w)
                <span class="wq-win wq-win-{{ $w }}" style="--i: {{ $i }}"></span>
            @endforeach
        </div>
        <div class="wq-facade-door"></div>
    </figure>

    <ul class="wq-auth-points">
        @foreach ($door['points'] as [$icon, $text])
            <li><x-filament::icon :icon="$icon" class="wq-auth-point-icon" /><span>{{ $text }}</span></li>
        @endforeach
    </ul>

    <div class="wq-auth-today">
        <span class="wq-today-day">{{ $day }}</span>
        <span class="wq-today-hijri">{{ $hijri }}</span>
        <span class="wq-today-greg">{{ $greg }}</span>
    </div>
</aside>
