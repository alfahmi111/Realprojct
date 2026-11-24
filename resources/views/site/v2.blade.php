@php
    /*
     * The new public page (preview at /preview, owner request 2026-11-25). Same facts as the current page; nothing on it
     * is invented: every feature is built, the screenshots are the system itself (demo data), the reconciliation figure is
     * marked as an illustration. Art direction (revised twice on 2026-11-25 at the owner's request): a light, sunny page;
     * the slogan «كل وحدة، بكل ما فيها» stays while three stories slide under it. Each story shows an architectural pencil
     * sketch drawn for the page (two-point perspective, hatching, a light watercolour wash with the unit-status colours on
     * windows) with glass cards shaped like the system's screens floating over it, and room for a licensed photo of a person.
     * The Kufic-cell building held by the logo's «embrace» follows as its own section.
     * Structure (owner request 2026-11-25, «like mamarat.co, your own way»): hero, a strip of commitments in place of client
     * logos, why (six cards), four apps in device frames, the building, core features, who it is for, plans (no prices: Q36),
     * an invitation band, what the platform never does, questions, the request form, a footer with columns.
     */
    $st = ['paid' => 'مؤجرة ومسددة', 'late' => 'عليها متأخرات', 'vacant' => 'شاغرة', 'maint' => 'في الصيانة', 'soon' => 'ستُخلى قريباً'];
    $floors = [
        ['الدور الرابع', [['401', 'paid', 'عقد حتى رمضان 1448'], ['402', 'paid', 'عقد حتى ذي الحجة 1448'], ['403', 'soon', 'أشعر المستأجر بعدم التجديد'], ['404', 'paid', 'عقد حتى شعبان 1449']]],
        ['الدور الثالث', [['301', 'late', 'دفعة متأخرة 12 يوماً'], ['302', 'paid', 'عقد حتى محرم 1449'], ['303', 'paid', 'عقد حتى صفر 1449'], ['304', 'vacant', 'في قائمة الانتظار 3 مهتمين']]],
        ['الدور الثاني', [['201', 'paid', 'عقد حتى رجب 1448'], ['202', 'maint', 'تجهيز قبل التسليم'], ['203', 'paid', 'عقد حتى شوال 1448'], ['204', 'paid', 'عقد حتى ربيع الأول 1449']]],
        ['الدور الأول', [['101', 'paid', 'عقد حتى جمادى الآخرة 1449'], ['102', 'paid', 'عقد حتى رمضان 1448'], ['103', 'late', 'فاتورة غير مسددة'], ['104', 'paid', 'عقد حتى ذي القعدة 1448']]],
    ];
    $shops = [['محل 1', 'paid', 'صيدلية، عقد تجاري حتى 1450'], ['محل 2', 'vacant', 'شاغر منذ 20 يوماً'], ['محل 3', 'paid', 'مغسلة، عقد حتى 1449']];
    // Each story: a pencil sketch drawn for the page, glass cards shaped like the system's own screens (sample text, no
    // figures), and an optional photo of a person: drop a licensed cut-out at public/site/v2/people/{key}.webp and it appears.
    $slides = [
        ['key' => 'owner', 'who' => 'للمالك', 'art' => 'sketch-villa', 'wh' => [744, 364], 'h' => 'كشف شهري يفصّل كل ريال في مبناك.',
         'p' => 'ما حُصّل، وما دُفع لك مباشرة، وأتعاب المكتب بنص عقد الإدارة، وكل مصروف صيانة برقم أمره. تقرؤه من جوالك.',
         'alt' => 'سكتش بالقلم الرصاص لفيلا حديثة',
         'cards' => [
             ['paid', 'وصل كشف حساب نوفمبر', 'الإيجار والأتعاب والصيانة وصافي الشهر', null],
             ['vacant', 'طلب موافقة على صيانة', 'مكيف شقة 203، فوق سقف الصرف', ['وافق', 'ارفض']],
         ]],
        ['key' => 'office', 'who' => 'للمكتب', 'art' => 'sketch-offices', 'wh' => [567, 466], 'h' => 'العقود والتحصيل والصيانة في مكان واحد.',
         'p' => 'صندوق مهام بما ينتظر قرارك، والمتأخرات، والعقود التي اقترب موعد إشعارها، ولكل وحدة سجل أصولها.',
         'alt' => 'سكتش بالقلم الرصاص لمبنى مكاتب بواجهات زجاجية',
         'cards' => [
             ['soon', 'ينتظرك اليوم 3 أمور', 'بلاغ جديد، ودفعة متأخرة، وعقد يقترب إشعاره', null],
             ['cells', 'عمارة النخيل', 'كل وحدة بلون حالتها اليوم', null],
         ]],
        ['key' => 'tenant', 'who' => 'للمستأجر والفني', 'art' => 'sketch-block', 'wh' => [547, 428], 'h' => 'بلاغ بصورة، وفني يعرف تاريخ الجهاز.',
         'p' => 'يبلّغ المستأجر عن العطل من جواله بصورة أو مقطع، ويستلم الفني أمر العمل ومعه سجل الأصل، ولو انقطع الإنترنت.',
         'alt' => 'سكتش بالقلم الرصاص لعمارة سكنية فوق محلات',
         'cards' => [
             ['late', 'بلاغ جديد: تسرب من المكيف', 'شقة 203، مع صورة', null],
             ['paid', 'أُسند البلاغ إلى الفني', 'تتابع حالته من بوابتك', null],
         ]],
    ];
    foreach ($slides as &$sl) { $sl['person'] = is_file(public_path("site/v2/people/{$sl['key']}.webp")); }
    unset($sl);
    $svg = fn (string $f) => file_get_contents(public_path("brand/$f.svg"));
    $i = 0;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>وِثاق | كل وحدة، بكل ما فيها</title>
    <meta name="description" content="منصة سعودية لمكاتب إدارة الأملاك: العقود والتحصيل وكشف حساب المالك، والصيانة وسجل أصول كل وحدة، وبوابات للمالك والمستأجر والفني، مع قواعد إيجار والضريبة مطبّقة.">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#E4F3EE">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('brand/withaq-favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('brand/icons/apple-touch-icon.png') }}">
    <link rel="preload" href="{{ asset('fonts/noto-kufi-arabic-var-arabic.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}?v={{ @filemtime(public_path('fonts/fonts.css')) }}">
    <link rel="stylesheet" href="{{ asset('site/v2/v2.css') }}?v={{ @filemtime(public_path('site/v2/v2.css')) }}">
</head>
<body class="w2">
<a class="w2-skip" href="#main">انتقل إلى المحتوى</a>

<header class="w2-head">
    <div class="w2-wrap w2-head-in">
        <a class="w2-brand" href="{{ route('site.preview') }}" aria-label="وِثاق، الصفحة الرئيسية"><span aria-hidden="true">{!! $svg('withaq-wordmark') !!}</span></a>
        <nav class="w2-nav" aria-label="أقسام الصفحة">
            <a href="#why">لماذا وِثاق</a>
            <a href="#roles">الواجهات</a>
            <a href="#features">المزايا</a>
            <a href="#who">لمن</a>
            <a href="#plans">الباقات</a>
            <a href="#faq">أسئلة</a>
        </nav>
        <div class="w2-head-cta">
            <a class="w2-textlink" href="{{ $loginUrl }}">دخول</a>
            <a class="w2-btn w2-btn-sm" href="#demo">اطلب عرضاً</a>
        </div>
    </div>
</header>

<main id="main">

{{-- 1. Opening: the slogan stays, three short stories slide under it (owner, office, tenant + technician), each with an original sketch --}}
<section class="w2-hero" aria-labelledby="hero-h">
    <div class="w2-wrap w2-hero-in" data-slider>
        <div class="w2-hero-text">
            <p class="w2-kicker">إدارة الأملاك والأصول للمكاتب العقارية في المملكة</p>
            <h1 id="hero-h" class="w2-slogan">كل وحدة،<br>بكل ما فيها.</h1>
            <div class="w2-slides" aria-roledescription="carousel" aria-label="ما يقدّمه وِثاق لكل طرف">
                @foreach ($slides as $n => $sl)
                    <div class="w2-slide{{ $n === 0 ? ' is-on' : '' }}" role="group" aria-roledescription="شريحة" aria-label="{{ $n + 1 }} من {{ count($slides) }}: {{ $sl['who'] }}" @if ($n) aria-hidden="true" @endif>
                        <p class="w2-slide-who">{{ $sl['who'] }}</p>
                        <p class="w2-slide-h">{{ $sl['h'] }}</p>
                        <p class="w2-slide-p">{{ $sl['p'] }}</p>
                    </div>
                @endforeach
            </div>
            <div class="w2-cta">
                <a class="w2-btn" href="#demo">اطلب عرضاً لمكتبك</a>
                <a class="w2-textlink" href="{{ $loginUrl }}">دخول المكاتب والملاك</a>
            </div>
            <div class="w2-slide-nav">
                <div class="w2-dots" role="group" aria-label="اختر الشريحة">
                    @foreach ($slides as $n => $sl)
                        <button type="button" class="w2-dot{{ $n === 0 ? ' is-on' : '' }}" data-go="{{ $n }}" @if ($n === 0) aria-current="true" @endif><span>{{ $sl['who'] }}</span><i aria-hidden="true"></i></button>
                    @endforeach
                </div>
                <button type="button" class="w2-pause" aria-label="إيقاف التقليب التلقائي" data-play="تشغيل التقليب التلقائي" data-stop="إيقاف التقليب التلقائي"><span aria-hidden="true"></span></button>
            </div>
        </div>
        <div class="w2-hero-art">
            @foreach ($slides as $n => $sl)
                <figure class="w2-scene{{ $n === 0 ? ' is-on' : '' }}" @if ($n) aria-hidden="true" @endif>
                    <img class="w2-sketch" src="{{ asset('site/v2/art/' . $sl['art'] . '.svg') }}" width="{{ $sl['wh'][0] }}" height="{{ $sl['wh'][1] }}" alt="{{ $sl['alt'] }}" fetchpriority="{{ $n ? 'low' : 'high' }}" decoding="async">
                    @if ($sl['person'])
                        <img class="w2-person" src="{{ asset('site/v2/people/' . $sl['key'] . '.webp') }}" alt="" loading="lazy" decoding="async">
                    @endif
                    @foreach ($sl['cards'] as $c => [$tone, $title, $sub, $acts])
                        <div class="w2-float w2-float-{{ $c + 1 }}" aria-hidden="true">
                            @if ($tone === 'cells')
                                <span class="w2-mini" aria-hidden="true">@foreach (['paid','paid','late','paid','vacant','paid','maint','paid','paid','soon','paid','paid'] as $m)<i class="w2-s-{{ $m }}"></i>@endforeach</span>
                            @else
                                <span class="w2-float-ic w2-s-{{ $tone }}" aria-hidden="true"></span>
                            @endif
                            <span class="w2-float-t"><b>{{ $title }}</b><small>{{ $sub }}</small>
                                @if ($acts)<span class="w2-float-acts"><em>{{ $acts[0] }}</em><em>{{ $acts[1] }}</em></span>@endif
                            </span>
                        </div>
                    @endforeach
                </figure>
            @endforeach
            <p class="w2-art-note">رسم بالقلم، والبطاقات أمثلة من واجهات النظام.</p>
        </div>
    </div>
</section>

{{-- 2. What the office counts on: facts in place of client logos (we show none until there are real clients) --}}
<section class="w2-strip" aria-label="ما يلتزم به النظام">
    <div class="w2-wrap">
        <ul class="w2-strip-list">
            @foreach ([['heroicon-o-document-check', 'تجهيز بيانات التوثيق في إيجار'], ['heroicon-o-receipt-percent', 'فواتير بالحقول التي تطلبها هيئة الزكاة'], ['heroicon-o-calendar-days', 'الهجري بتقويم أم القرى'], ['heroicon-o-lock-closed', 'بيانات كل مكتب معزولة'], ['heroicon-o-finger-print', 'سجل تدقيق لا يُعدَّل']] as [$ic, $t])
                <li>@svg($ic, 'w2-strip-ic')<span>{{ $t }}</span></li>
            @endforeach
        </ul>
    </div>
</section>

{{-- 4. Why Withaq: six things the office knows without asking --}}
<section class="w2-why" id="why" aria-labelledby="why-h">
    <div class="w2-wrap">
        <div class="w2-sec-title">
            <h2 id="why-h">لماذا وِثاق؟</h2>
            <p>ستة أشياء يعرفها المكتب والمالك دون أن يسألا أحداً.</p>
        </div>
        <div class="w2-why-grid">
            @foreach ([
                ['heroicon-o-document-chart-bar', 'تعرف أين ذهب مالك', 'كشف شهري لكل مالك: ما حُصّل، وما دُفع له مباشرة، وأتعاب المكتب بنص عقد الإدارة، وكل مصروف صيانة برقم أمره.'],
                ['heroicon-o-banknotes', 'أمانات كل مالك منفصلة', 'لا يُحوَّل للمالك أكثر مما في أماناته فعلاً، والإلغاء والصرف والرد بموافقة شخصين.'],
                ['heroicon-o-archive-box', 'تعرف ما تملك', 'سجل أصول لكل وحدة: المكيف والسخان والفرن بقيمته وإهلاكه، يُجرد عند التسليم والاستلام فيظهر ما تغيّر.'],
                ['heroicon-o-wrench-screwdriver', 'صيانة بأوامر عمل', 'تصحيحية ووقائية وطارئة، وسقف صرف لا يُتجاوز دون موافقة المالك، وصور قبل العمل وبعده.'],
                ['heroicon-o-scale', 'تبقى نظامياً', 'قواعد مثل إيقاف زيادة الإيجار في الرياض وأسباب رفض التجديد محفوظة في جدول يُحدَّث، فيمنع النظام الخطأ قبل وقوعه.'],
                ['heroicon-o-arrow-path', 'تسوية كل ليلة', 'تُطابَق العقود بالفواتير والدفعات وملف إيجار، فيبدأ صباحك بقائمة ما يحتاج مراجعة فقط.'],
            ] as [$ic, $t, $d])
                <article class="w2-card">
                    <span class="w2-card-ic">@svg($ic)</span>
                    <h3>{{ $t }}</h3>
                    <p>{{ $d }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- 5. Four windows on one record: product cards with the real screens in device frames --}}
<section class="w2-apps" id="roles" aria-labelledby="roles-h">
    <div class="w2-wrap">
        <div class="w2-sec-title">
            <h2 id="roles-h">أربع نوافذ على سجل واحد</h2>
            <p>كل طرف يرى السجل نفسه من نافذته. هذه شاشات النظام ببيانات تجريبية.</p>
        </div>
        <div class="w2-apps-grid">
            @foreach ([
                ['office', 'لوحة المكتب', 'صباح المكتب في شاشة واحدة', 'صندوق مهام بما ينتظر قرارك، والبلاغات الجديدة، والمتأخرات، والعقود التي اقترب موعد إشعارها.',
                 ['شجرة العقارات والمباني والوحدات', 'تقويم الإتاحة', 'عرض تأجير يمر على اعتماداتك', 'نقل بياناتك من Excel أو من عقود إيجار', 'حصر كل موظف في عقاراته', 'التسوية الليلية'], 'desk', 'dashboard', [1600, 878]],
                ['owner', 'بوابة المالك', 'مبناك وكشفه في جيبك', 'عقاراته وإشغالها وتحصيلها، وكشف حسابه الشهري، والموافقة على الصرف فوق السقف من جواله.',
                 ['كشف يفصّل الإيجار والأتعاب والصيانة', 'طلبات الموافقة بتقديرها وصورها', 'قاعدة الموافقة بعقد إدارة يحفظ القديم', 'تحقق بخطوتين'], 'desk', 'statement', [1000, 855]],
                ['tenant', 'بوابة المستأجر', 'عقده وفواتيره في مكان واحد', 'موعد الإشعار قبل التجديد، وبلاغ العطل بصورة أو مقطع، والتعاميم من إدارة المبنى.',
                 ['تذكير بالدفعة قبل موعدها', 'الأصول المسجلة على وحدته', 'حالة البلاغ خطوة بخطوة', 'التعاميم بالصور والملفات'], 'phone', 'tenant', [720, 1551]],
                ['tech', 'تطبيق الفني', 'يعمل ولو انقطع الإنترنت', 'أوامره لليوم، وباركود الأصل ليعرف تاريخه، وصور قبل العمل وبعده.',
                 ['بطاقة لكل أمر بموقعه ووصفه', 'تسجيل القطع والوقت والتكلفة', 'مزامنة تلقائية عند عودة الاتصال', 'مهامه مرتبة بالأولوية'], 'phone', 'tech', [720, 1551]],
            ] as [$k, $tag, $t, $d, $list, $frame, $shot, $wh])
                <article class="w2-app w2-app-{{ $k }}">
                    <div class="w2-app-media w2-app-media-{{ $frame }}">
                        @if ($frame === 'desk')
                            <div class="w2-laptop"><div class="w2-laptop-screen"><img src="{{ asset("site/v2/shots/$shot.webp") }}" width="{{ $wh[0] }}" height="{{ $wh[1] }}" alt="{{ $tag }}: {{ $t }}" loading="lazy" decoding="async"></div><div class="w2-laptop-base" aria-hidden="true"></div></div>
                        @else
                            <div class="w2-phone"><img src="{{ asset("site/v2/shots/$shot.webp") }}" width="{{ $wh[0] }}" height="{{ $wh[1] }}" alt="{{ $tag }}: {{ $t }}" loading="lazy" decoding="async"></div>
                        @endif
                    </div>
                    <div class="w2-app-body">
                        <span class="w2-tag">{{ $tag }}</span>
                        <h3>{{ $t }}</h3>
                        <p>{{ $d }}</p>
                        <ul class="w2-checks">@foreach ($list as $li)<li>{{ $li }}</li>@endforeach</ul>
                    </div>
                </article>
            @endforeach
        </div>
        <p class="w2-apps-note">@svg('heroicon-o-device-phone-mobile', 'w2-note-ic') يُثبَّت من المتصفح على الجوال دون متجر تطبيقات. الملاك والمستأجرون والفنيون دون رسوم على المكتب.</p>
    </div>
</section>

{{-- 6. The building as the office sees it: Kufic cells in each unit's status colour, held by the logo's embrace --}}
<section class="w2-build" id="building" aria-labelledby="build-h">
    <div class="w2-wrap w2-build-in">
        <div class="w2-build-text">
            <h2 id="build-h">هكذا يرى المكتب مبناه كل صباح</h2>
            <p>كل خانة وحدة بلون حالتها اليوم. مرّر على أي خانة أو اضغطها.</p>
            <div class="w2-cap" aria-live="polite">
                <strong class="w2-cap-unit">19 وحدة في مبنى واحد</strong>
                <span class="w2-cap-note">اختر خانة لترى حالتها.</span>
            </div>
            <ul class="w2-legend" aria-label="ألوان الحالة">
                @foreach ($st as $k => $label)<li><i class="w2-s-{{ $k }}" aria-hidden="true"></i>{{ $label }}</li>@endforeach
            </ul>
        </div>
        <figure class="w2-facade" aria-label="مثال لمبنى في النظام: كل خانة وحدة بلون حالتها اليوم">
            <div class="w2-facade-box">
                <span class="w2-emb w2-emb-r" aria-hidden="true"></span><span class="w2-emb w2-emb-b" aria-hidden="true"></span><span class="w2-emb w2-emb-l" aria-hidden="true"></span>
                <div class="w2-cells" role="list">
                    @foreach ($floors as [$floor, $flats])
                        @foreach ($flats as [$code, $s, $note])
                            <button type="button" role="listitem" class="w2-cell w2-s-{{ $s }}" style="--i: {{ 19 - (++$i) }}" data-unit="شقة {{ $code }}" data-state="{{ $st[$s] }}" data-note="{{ $note }}" aria-label="{{ $floor }}، شقة {{ $code }}: {{ $st[$s] }}، {{ $note }}"><span>{{ $code }}</span></button>
                        @endforeach
                    @endforeach
                    @foreach ($shops as [$code, $s, $note])
                        <button type="button" role="listitem" class="w2-cell w2-shop w2-s-{{ $s }}" style="--i: {{ 19 - (++$i) }}" data-unit="{{ $code }}" data-state="{{ $st[$s] }}" data-note="{{ $note }}" aria-label="الدور الأرضي، {{ $code }}: {{ $st[$s] }}، {{ $note }}"><span>{{ $code }}</span></button>
                    @endforeach
                    <span class="w2-door" aria-hidden="true"><svg viewBox="0 0 7 7" class="w2-qr-mark"><path d="M0 0h3v3H0zM4 0h3v3H4zM0 4h3v3H0zM4 4h1v1H4zM6 4h1v1H6zM5 5h1v1H5zM4 6h1v1H4zM6 6h1v1H6z" fill="currentColor"/><path d="M1 1h1v1H1zM5 1h1v1H5zM1 5h1v1H1z" fill="#fff"/></svg></span>
                </div>
            </div>
        </figure>
    </div>
</section>

{{-- 7. Core features: the real screens, an illustration that says it is one, and two sketches --}}
<section class="w2-feat" id="features" aria-labelledby="feat-h">
    <div class="w2-wrap">
        <div class="w2-sec-title">
            <h2 id="feat-h">المزايا الأساسية</h2>
            <p>ما يعمل اليوم في النظام، من التسليم إلى كشف آخر الشهر.</p>
        </div>
        <div class="w2-feat-grid">
            <article class="w2-feat-card"><div class="w2-feat-img"><img src="{{ asset('site/v2/shots/statement.webp') }}" width="1000" height="855" alt="كشف حساب المالك في بوابته" loading="lazy" decoding="async"></div>
                <h3>كشف حساب المالك</h3><p>الإيجار المحصّل وما دُفع مباشرة والأتعاب والصيانة وصافي الشهر، كما يراه المالك.</p></article>
            <article class="w2-feat-card"><div class="w2-feat-img"><img src="{{ asset('site/v2/shots/assets.webp') }}" width="1300" height="905" alt="جدول الأصول بقيمتها ونسبة إهلاكها" loading="lazy" decoding="async"></div>
                <h3>سجل الأصول والإهلاك</h3><p>كل أصل بموقعه وحالته وقيمته الحالية وموعد خروجه من الخدمة.</p></article>
            <article class="w2-feat-card"><div class="w2-feat-img"><img src="{{ asset('site/v2/shots/facade.webp') }}" width="1100" height="857" alt="واجهة المبنى في شاشة المكتب" loading="lazy" decoding="async"></div>
                <h3>واجهة المبنى اليوم</h3><p>كل وحدة بلون حالتها، وعدد كل حالة تحت الواجهة.</p></article>
            <article class="w2-feat-card">
                <div class="w2-feat-img w2-feat-recon" role="img" aria-label="مثال توضيحي للتسوية الليلية: شقة 103 بلا دفعة والباقي متطابق">
                    <div class="w2-recon-grid" aria-hidden="true">
                        <div class="w2-recon-h"><span>العقد</span><span>إيجار</span><span>الفاتورة</span><span>الدفعة</span></div>
                        <div><span>شقة 101</span><span class="ok">موثّق</span><span class="ok">صادرة</span><span class="ok">مسددة</span></div>
                        <div class="flag"><span>شقة 103</span><span class="ok">موثّق</span><span class="ok">صادرة</span><span class="bad">لا دفعة</span></div>
                        <div><span>محل 1</span><span class="ok">موثّق</span><span class="ok">صادرة</span><span class="ok">مسددة</span></div>
                    </div>
                    <span class="w2-recon-note">مثال توضيحي</span>
                </div>
                <h3>التسوية الليلية</h3><p>تُطابَق العقود بالفواتير والدفعات وملف إيجار، وما لا يتطابق يصلك صباحاً.</p></article>
            <article class="w2-feat-card"><div class="w2-feat-img w2-feat-sketch"><img src="{{ asset('site/v2/art/sketch-block.svg') }}" width="547" height="428" alt="" loading="lazy" decoding="async"></div>
                <h3>باركود المبنى وقائمة الانتظار</h3><p>لوحة على المدخل؛ من يمسحها يسجّل اهتمامه مجاناً، فتعرف كم ينتظر وحدتك قبل أن تشغر.</p></article>
            <article class="w2-feat-card"><div class="w2-feat-img w2-feat-sketch"><img src="{{ asset('site/v2/art/sketch-offices.svg') }}" width="567" height="466" alt="" loading="lazy" decoding="async"></div>
                <h3>انتقال دون بداية من الصفر</h3><p>املأ قالب الانتقال بعقودك أو ارفع عقود إيجار، فيقرأها النظام وتؤكدها قبل أن تُضاف.</p></article>
        </div>
    </div>
</section>

{{-- 8. Who it is for: the three plans' audiences --}}
<section class="w2-who-sec" id="who" aria-labelledby="who-h">
    <div class="w2-wrap">
        <div class="w2-sec-title">
            <h2 id="who-h">لمن صُمّم وِثاق؟</h2>
            <p>لمن يدير عقارات في المملكة، بنفسه أو لملاك آخرين.</p>
        </div>
        <div class="w2-who-grid">
            @foreach ([
                ['heroicon-o-building-office-2', 'مكاتب إدارة الأملاك', 'تدير عقارات ملاك متعددين، وتحتاج كشفاً لكل مالك وأمانات منفصلة وفريقاً بصلاحيات واضحة.',
                 ['محاسبة الأمانات وتعدد الملاك والموردون', 'حصر كل موظف في العقارات التي يتولاها', 'عروض تأجير تمر على اعتماداتك', 'شعار المكتب ولونه على البوابات']],
                ['heroicon-o-home-modern', 'الملاك الأفراد', 'تملك عمارة أو بضع وحدات وتديرها بنفسك، حتى 20 وحدة.',
                 ['الصيانة وسجل الأصول وجرد التسليم', 'بوابة لكل مستأجر', 'تذكير المستأجر بالدفعة', 'الهجري والميلادي معاً']],
                ['heroicon-o-briefcase', 'الشركات العقارية', 'محافظ كبيرة بلا حد لعدد الوحدات، وهوية كاملة، وربط مع المحاسبة.',
                 ['وحدات غير محدودة', 'هوية كاملة ونطاق خاص', 'الربط مع برامج المحاسبة', 'موافقة شخصين على الصرف']],
            ] as [$ic, $t, $d, $list])
                <article class="w2-who-card">
                    <span class="w2-card-ic">@svg($ic)</span>
                    <h3>{{ $t }}</h3>
                    <p>{{ $d }}</p>
                    <ul class="w2-checks w2-checks-one">@foreach ($list as $li)<li>{{ $li }}</li>@endforeach</ul>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- 9. Plans: priced by units, prices announced at launch (Q36) --}}
<section class="w2-plans" id="plans" aria-labelledby="plans-h">
    <div class="w2-wrap">
        <div class="w2-sec-title">
            <h2 id="plans-h">الباقات</h2>
            <p>التسعير بعدد الوحدات لا بعدد المستخدمين. الأسعار تُعلن عند الإطلاق.</p>
        </div>
        <div class="w2-plan-grid">
            @foreach ([
                ['مالك', 'لمن يدير عقاراته بنفسه.', 'حتى 20 وحدة', [['الصيانة وسجل الأصول وجرد التسليم', 1], ['بوابة المستأجر وبوابة المالك', 1], ['هوية المكتب: الشعار', 1], ['محاسبة الأمانات وتعدد الملاك', 0], ['الربط مع برامج المحاسبة', 0]]],
                ['مكتب', 'لمكاتب إدارة الأملاك.', 'حتى 300 وحدة', [['كل ما في باقة مالك', 1], ['محاسبة الأمانات وتعدد الملاك والموردون', 1], ['هوية المكتب: الشعار واللون', 1], ['تصدير القيود المحاسبية', 1], ['الربط المباشر مع برامج المحاسبة', 0]]],
                ['شركة', 'للشركات والمحافظ الكبيرة.', 'وحدات غير محدودة', [['كل ما في باقة مكتب', 1], ['هوية كاملة ونطاق خاص', 1], ['الربط مع برامج المحاسبة', 1]]],
            ] as $n => [$name, $for, $cap, $rows])
                <article class="w2-plan{{ $n === 1 ? ' w2-plan-mid' : '' }}">
                    <h3>{{ $name }}</h3>
                    <p class="w2-plan-for">{{ $for }}</p>
                    <p class="w2-plan-units">{{ $cap }}</p>
                    <p class="w2-plan-price">السعر يُعلن عند الإطلاق</p>
                    <a class="w2-btn {{ $n === 1 ? '' : 'w2-btn-line' }}" href="#demo">اطلب عرضاً</a>
                    <ul class="w2-plan-list">
                        @foreach ($rows as [$r, $yes])<li class="{{ $yes ? 'is-yes' : 'is-no' }}"><span class="w2-sr">{{ $yes ? 'متاح:' : 'غير متاح:' }}</span>{{ $r }}</li>@endforeach
                    </ul>
                </article>
            @endforeach
        </div>
        <div class="w2-plan-all">
            <h3>كل الباقات تشمل</h3>
            <ul>
                <li>مستخدمون غير محدودين</li>
                <li>الصيانة وسجل الأصول وجرد التسليم</li>
                <li>بوابة المستأجر وبوابة المالك وتطبيق الفني</li>
                <li>الهجري بتقويم أم القرى في كل شاشة ومستند</li>
            </ul>
        </div>
    </div>
</section>

{{-- 10. The invitation band --}}
<section class="w2-band" aria-labelledby="band-h">
    <div class="w2-wrap w2-band-in">
        <h2 id="band-h">جرّبه على مبنى من مبانيك.</h2>
        <div>
            <p>نعرض النظام على بيانات قريبة من محفظتك، ونساعدك في نقل عقودك الحالية.</p>
            <a class="w2-btn w2-btn-sun" href="#demo">اطلب عرضاً لمكتبك</a>
        </div>
    </div>
</section>

{{-- 11. What the platform never does --}}
<section class="w2-never-sec" aria-labelledby="never-h">
    <div class="w2-wrap">
        <div class="w2-sec-title">
            <h2 id="never-h">ما لا تفعله المنصة</h2>
            <p>حدود نلتزم بها، مكتوبة في النظام نفسه.</p>
        </div>
        <ul class="w2-never-grid">
            <li><span class="w2-card-ic">@svg('heroicon-o-banknotes')</span><b>لا تستلم أموالاً.</b> الإيجار في حساب المالك، أو في أمانات المكتب حين يسمح عقد الإدارة.</li>
            <li><span class="w2-card-ic">@svg('heroicon-o-lock-closed')</span><b>لا يرى مكتب بيانات مكتب آخر.</b> العزل في قاعدة البيانات نفسها، لا في الشاشات فقط.</li>
            <li><span class="w2-card-ic">@svg('heroicon-o-finger-print')</span><b>لا يُحذف قيد مالي.</b> كل إجراء في سجل تدقيق لا يُعدَّل.</li>
            <li><span class="w2-card-ic">@svg('heroicon-o-megaphone')</span><b>لا تنشر باسمك.</b> التوثيق في إيجار يبقى بيدك.</li>
        </ul>
    </div>
</section>

{{-- 12. Questions --}}
<section class="w2-faq" id="faq" aria-labelledby="faq-h">
    <div class="w2-wrap w2-faq-in">
        <h2 id="faq-h">أسئلة يطرحها المكتب قبل أن يبدأ</h2>
        <div class="w2-faq-list">
            <details><summary>هل تمر أموال المستأجرين عبر المنصة؟</summary><p>لا. المنصة لا تستلم أموالاً ولا تحتفظ بها. الإيجار يُودَع في حساب المالك، أو في حساب أمانات المكتب حين يسمح بذلك عقد الإدارة، والمنصة تسجّل وتطابق فقط.</p></details>
            <details><summary>هل تُوثَّق العقود في إيجار من داخل النظام؟</summary><p>يجهّز النظام بيانات العقد كاملة بالشكل الذي تطلبه منصة إيجار، وتوثّقه أنت هناك ثم تُدخل رقمه، ويتابع النظام حالته. الربط الآلي المباشر يأتي بعد اعتماد المنصة في برنامج إيجار.</p></details>
            <details><summary>هل يرى مكتب آخر بياناتي؟</summary><p>لا. بيانات كل مكتب معزولة على مستوى قاعدة البيانات نفسها، لا على مستوى الشاشات فقط، وكل إجراء مالي يُسجَّل في سجل تدقيق لا يُعدَّل ولا يُحذف.</p></details>
            <details><summary>عندي بيانات في ملفات Excel، هل أبدأ من الصفر؟</summary><p>لا. نزّل قالب الانتقال السريع واملأه بعقودك، أو ارفع عقود إيجار، فيقرأها النظام ويعرضها عليك لتؤكدها قبل أن تُضاف.</p></details>
            <details><summary>هل التواريخ بالهجري؟</summary><p>تختار طريقة العرض: هجري أولاً أو ميلادي أولاً أو كلاهما، والنظام يحفظها ميلادياً ليبقى الحساب دقيقاً.</p></details>
        </div>
    </div>
</section>

{{-- the kinds of property an office runs, in pencil, sitting on the request band --}}
<div class="w2-skyline" aria-hidden="true"><img src="{{ asset('site/v2/art/sketch-street.svg') }}" width="1400" height="440" alt="" loading="lazy" decoding="async"></div>

{{-- 13. Demo request --}}
<section class="w2-demo" id="demo" aria-labelledby="demo-h">
    <div class="w2-wrap w2-demo-in">
        <div class="w2-demo-text">
            <h2 id="demo-h">شاهد مبناك في النظام</h2>
            <p>اترك بياناتك ونتواصل معك لعرض النظام على بيانات قريبة من محفظتك، ونساعدك في نقل عقودك الحالية.</p>
        </div>
        @if (session('demo_sent'))
            <div class="w2-sent" role="status"><strong>وصل طلبك.</strong><p>نتواصل معك على رقم جوالك خلال يومي عمل.</p></div>
        @else
            <form class="w2-form" method="post" action="{{ route('site.demo') }}" novalidate>
                @csrf
                <input type="hidden" name="from" value="preview">
                <div class="w2-hp" aria-hidden="true"><label>موقعك <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                <label>الاسم<input type="text" name="name" value="{{ old('name') }}" required autocomplete="name" @error('name') aria-invalid="true" aria-describedby="e-name" @enderror></label>
                @error('name')<span class="w2-err" id="e-name">{{ $message }}</span>@enderror
                <label>المكتب أو الشركة<input type="text" name="organization" value="{{ old('organization') }}" autocomplete="organization"></label>
                <div class="w2-two">
                    <label>الجوال<input type="tel" name="phone" value="{{ old('phone') }}" required inputmode="tel" autocomplete="tel" placeholder="05xxxxxxxx" dir="ltr" @error('phone') aria-invalid="true" aria-describedby="e-phone" @enderror></label>
                    <label>المدينة<input type="text" name="city" value="{{ old('city') }}"></label>
                </div>
                @error('phone')<span class="w2-err" id="e-phone">{{ $message }}</span>@enderror
                <div class="w2-two">
                    <label>البريد الإلكتروني (اختياري)<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" dir="ltr"></label>
                    <label>عدد الوحدات التي تديرها
                        <select name="units"><option value="">اختر</option>@foreach ($units as $k => $v)<option value="{{ $k }}" @selected(old('units') === $k)>{{ $v }}</option>@endforeach</select>
                    </label>
                </div>
                @error('email')<span class="w2-err">{{ $message }}</span>@enderror
                <label>ما الذي تريد أن تراه؟ (اختياري)<textarea name="message" rows="3" maxlength="1000">{{ old('message') }}</textarea></label>
                <label class="w2-check"><input type="checkbox" name="consent" value="1" @checked(old('consent')) @error('consent') aria-invalid="true" @enderror> أوافق على استخدام بياناتي للتواصل معي بشأن هذا العرض فقط.</label>
                @error('consent')<span class="w2-err">{{ $message }}</span>@enderror
                <button type="submit" class="w2-btn">أرسل الطلب</button>
            </form>
        @endif
    </div>
</section>
</main>

<footer class="w2-foot">
    <div class="w2-wrap">
        <div class="w2-foot-in">
            <div class="w2-foot-brand">
                <span class="w2-foot-logo" aria-hidden="true">{!! $svg('withaq-stacked') !!}</span>
                <p>كل وحدة، بكل ما فيها. مزوّد برمجيات لإدارة الأملاك في المملكة العربية السعودية؛ لا نستلم أموالاً ولا ندير العقارات بأنفسنا.</p>
            </div>
            <nav class="w2-foot-col" aria-label="النظام"><b>النظام</b><a href="#why">لماذا وِثاق</a><a href="#roles">الواجهات</a><a href="#features">المزايا</a><a href="#building">واجهة المبنى</a></nav>
            <nav class="w2-foot-col" aria-label="البدء"><b>البدء</b><a href="#who">لمن صُمّم</a><a href="#plans">الباقات</a><a href="#faq">أسئلة شائعة</a><a href="#demo">اطلب عرضاً</a></nav>
            <nav class="w2-foot-col" aria-label="الدخول"><b>الدخول</b><a href="{{ $loginUrl }}">دخول المكاتب والملاك</a></nav>
        </div>
        <p class="w2-foot-base">© {{ date('Y') }} وِثاق</p>
    </div>
</footer>

<script>
(() => {
    // the opening: three stories under the slogan. Auto-advances every 7s; pauses on hover, on focus, with the button,
    // when the tab is hidden, and never starts under reduced motion. Swipe on touch.
    const root = document.querySelector('[data-slider]');
    const slides = [...root.querySelectorAll('.w2-slide')], arts = [...root.querySelectorAll('.w2-scene')], dots = [...root.querySelectorAll('.w2-dot')];
    const pause = root.querySelector('.w2-pause');
    const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
    let at = 0, timer = null, stopped = still, held = false;
    const go = (n) => {
        at = (n + slides.length) % slides.length;
        [slides, arts].forEach((set) => set.forEach((el, k) => { el.classList.toggle('is-on', k === at); el.setAttribute('aria-hidden', k !== at); }));
        dots.forEach((d, k) => { d.classList.toggle('is-on', k === at); k === at ? d.setAttribute('aria-current', 'true') : d.removeAttribute('aria-current'); });
        restart();
    };
    const restart = () => {
        clearTimeout(timer);
        root.classList.remove('is-running'); void root.offsetWidth;
        if (!stopped && !held && !document.hidden) { root.classList.add('is-running'); timer = setTimeout(() => go(at + 1), 7000); }
    };
    const setStopped = (v) => { stopped = v; root.classList.toggle('is-stopped', v); pause.setAttribute('aria-label', v ? pause.dataset.play : pause.dataset.stop); restart(); };
    dots.forEach((d) => d.addEventListener('click', () => go(+d.dataset.go)));
    pause.addEventListener('click', () => setStopped(!stopped));
    root.addEventListener('mouseenter', () => { held = true; restart(); });
    root.addEventListener('mouseleave', () => { held = false; restart(); });
    root.addEventListener('focusin', () => { held = true; restart(); });
    root.addEventListener('focusout', () => { held = false; restart(); });
    document.addEventListener('visibilitychange', restart);
    let x0 = null;
    const art = root.querySelector('.w2-hero-art');
    art.addEventListener('pointerdown', (e) => { x0 = e.clientX; });
    art.addEventListener('pointerup', (e) => { if (x0 === null) return; const dx = e.clientX - x0; x0 = null; if (Math.abs(dx) > 40) go(at + (dx > 0 ? 1 : -1)); });   // RTL: swipe right = next
    setStopped(stopped);
    // a story can be linked directly: /preview#story-2
    const m = /^#story-(\d)$/.exec(location.hash);
    if (m && +m[1] >= 1 && +m[1] <= slides.length) go(+m[1] - 1);
    // the facade: hovering, focusing or tapping a cell explains it
    const cap = document.querySelector('.w2-cap');
    const u = cap.querySelector('.w2-cap-unit'), n = cap.querySelector('.w2-cap-note');
    const show = (c) => { u.textContent = c.dataset.unit + ': ' + c.dataset.state; n.textContent = c.dataset.note; };
    document.querySelectorAll('.w2-cell').forEach((c) => ['mouseenter', 'focus', 'click'].forEach((e) => c.addEventListener(e, () => show(c))));

    // the header gains an edge once the opening has scrolled past
    const head = document.querySelector('.w2-head');
    const io = new IntersectionObserver(([e]) => head.classList.toggle('is-past', !e.isIntersecting), { rootMargin: '-80px 0px 0px 0px' });
    io.observe(document.querySelector('.w2-hero'));

    // the building rises and the embrace is drawn once, when its section first comes into view
    const build = document.querySelector('.w2-build');
    if ('IntersectionObserver' in window) {
        const seen = new IntersectionObserver(([e]) => { if (e.isIntersecting) { build.classList.add('is-seen'); seen.disconnect(); } }, { threshold: .3 });
        seen.observe(build);
    } else { build.classList.add('is-seen'); }
})();
</script>
</body>
</html>
