@php
    /*
     * The new public page (preview at /preview, owner request 2026-11-25). Same facts as the current page; nothing on it
     * is invented: every feature is built, the screenshots are the system itself (demo data), the reconciliation figure is
     * marked as an illustration. Art direction (revised twice on 2026-11-25 at the owner's request): a light, sunny page;
     * the slogan «كل وحدة، بكل ما فيها» stays while three stories slide under it. Each story shows an architectural pencil
     * sketch drawn for the page (two-point perspective, hatching, a light watercolour wash with the unit-status colours on
     * windows) with glass cards shaped like the system's screens floating over it, and room for a licensed photo of a person.
     * The Kufic-cell building held by the logo's «embrace» follows as its own section.
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
            <a href="#day">يوم المكتب</a>
            <a href="#money">المال</a>
            <a href="#roles">الواجهات</a>
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

{{-- 2. The building as the office sees it: Kufic cells in each unit's status colour, held by the logo's embrace --}}
<section class="w2-build" aria-labelledby="build-h">
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

{{-- 2. The problem, told by the three parties --}}
<section class="w2-voices" aria-labelledby="voices-h">
    <div class="w2-wrap">
        <h2 id="voices-h" class="w2-eyebrow-h">مبنى واحد، وثلاث روايات</h2>
        <ol class="w2-voice-list">
            <li><span class="w2-who">المالك</span><p>لا يعرف كم أدرّ مبناه هذا الشهر، ولا كم أخذ المكتب منه.</p></li>
            <li><span class="w2-who">المكتب</span><p>يدير العقود والدفعات بين ملف Excel ومحادثات واتساب.</p></li>
            <li><span class="w2-who">المستأجر</span><p>لا يعرف إلى من يتوجه حين يتعطل المكيف.</p></li>
        </ol>
        <p class="w2-resolve">وِثاق يجعلها رواية واحدة: سجل لكل وحدة، يراه كل طرف من نافذته.</p>
    </div>
</section>

{{-- 3. The office's morning, the real screen --}}
<section class="w2-day" id="day" aria-labelledby="day-h">
    <div class="w2-wrap">
        <div class="w2-day-head">
            <h2 id="day-h">صباح المكتب في شاشة واحدة</h2>
            <p>هذه شاشة النظام نفسه ببيانات تجريبية، لا رسم توضيحي.</p>
        </div>
        <figure class="w2-shot w2-shot-wide">
            <div class="w2-shot-frame">
                <img src="{{ asset('site/v2/shots/dashboard.webp') }}" width="1600" height="878" alt="الشاشة الرئيسية لمدير المكتب: تحية بعدد الأمور التي تنتظره، وقائمة تجهيز المكتب، ومؤشرات الإشغال والتحصيل والمتأخرات وأوامر العمل" loading="lazy" decoding="async">
            </div>
            <figcaption>
                <ul class="w2-pins">
                    <li><b>ما ينتظرك اليوم</b> قبل أن تفتح أي قائمة، والتاريخ بالهجري والميلادي.</li>
                    <li><b>تجهيز المكتب</b> خطوة بخطوة حتى أول كشف حساب.</li>
                    <li><b>الإشغال والتحصيل والمتأخرات</b> محسوبة من العقود والدفعات نفسها.</li>
                    <li><b>كل الأقسام في شريط واحد</b> العقارات والتأجير والصيانة والمالية.</li>
                </ul>
            </figcaption>
        </figure>
    </div>
</section>

{{-- 4. Three things you know without asking --}}
<section class="w2-know" id="money" aria-labelledby="know-h">
    <div class="w2-wrap">
        <h2 id="know-h" class="w2-sr">ثلاثة أشياء تعرفها دون أن تسأل أحداً</h2>

        <article class="w2-row">
            <div class="w2-row-text">
                <h3>تعرف أين ذهب مالك</h3>
                <p>كشف شهري لكل مالك: ما حُصّل، وما دُفع له مباشرة، وأتعاب المكتب بنص عقد الإدارة، وكل مصروف صيانة برقم أمره.</p>
                <ul class="w2-facts">
                    <li>حساب أمانات منفصل لكل مالك</li>
                    <li>لا يُحوَّل للمالك أكثر مما في أماناته فعلاً</li>
                    <li>موافقة شخصين على الإلغاء والصرف والرد</li>
                </ul>
            </div>
            <figure class="w2-shot">
                <div class="w2-shot-frame"><img src="{{ asset('site/v2/shots/statement.webp') }}" width="1000" height="855" alt="كشف حساب المالك لشهر نوفمبر: الإيجار المحصّل، وما دُفع للمالك مباشرة، وأتعاب الإدارة، وصافي المحوَّل" loading="lazy" decoding="async"></div>
                <figcaption>كشف الحساب كما يراه المالك في بوابته.</figcaption>
            </figure>
        </article>

        <article class="w2-row w2-row-flip">
            <div class="w2-row-text">
                <h3>تعرف ما تملك</h3>
                <p>لكل وحدة سجل أصول: المكيف والسخان والفرن، بقيمته الحالية وإهلاكه وموعد خروجه من الخدمة. يُجرد عند التسليم والاستلام، فيظهر ما تغيّر.</p>
                <ul class="w2-facts">
                    <li>صيانة تصحيحية ووقائية وطارئة بأوامر عمل</li>
                    <li>تطبيق للفني يعمل دون إنترنت ويرفع الصور</li>
                    <li>سقف صرف لا يُتجاوز دون موافقة المالك</li>
                </ul>
            </div>
            <figure class="w2-shot">
                <div class="w2-shot-frame"><img src="{{ asset('site/v2/shots/assets.webp') }}" width="1300" height="905" alt="جدول الأصول: كل أصل بموقعه وفئته وحالته وقيمته الحالية ونسبة إهلاكه وموعد خروجه من الخدمة" loading="lazy" decoding="async"></div>
                <figcaption>سجل الأصول في شاشة المكتب.</figcaption>
            </figure>
        </article>

        <article class="w2-row">
            <div class="w2-row-text">
                <h3>تبقى نظامياً دون أن تتابع الأنظمة</h3>
                <p>قواعد مثل إيقاف زيادة الإيجار في الرياض وأسباب رفض التجديد محفوظة في جدول يُحدَّث، فيمنع النظام الخطأ قبل وقوعه. وكل ليلة تُطابَق العقود بالفواتير والدفعات وملف إيجار.</p>
                <ul class="w2-facts">
                    <li>تجهيز بيانات التوثيق في إيجار ومتابعتها</li>
                    <li>فواتير بالحقول التي تطلبها هيئة الزكاة</li>
                    <li>التاريخ الهجري بتقويم أم القرى في كل شاشة ومستند</li>
                </ul>
            </div>
            <figure class="w2-recon" aria-label="مثال توضيحي للتسوية الليلية">
                <div class="w2-recon-grid" role="table" aria-label="مطابقة العقود بالفواتير والدفعات وملف إيجار">
                    <div role="row" class="w2-recon-h"><span role="columnheader">العقد</span><span role="columnheader">إيجار</span><span role="columnheader">الفاتورة</span><span role="columnheader">الدفعة</span></div>
                    <div role="row"><span role="cell">شقة 101</span><span role="cell" class="ok">موثّق</span><span role="cell" class="ok">صادرة</span><span role="cell" class="ok">مسددة</span></div>
                    <div role="row"><span role="cell">شقة 102</span><span role="cell" class="ok">موثّق</span><span role="cell" class="ok">صادرة</span><span role="cell" class="ok">مسددة</span></div>
                    <div role="row" class="flag"><span role="cell">شقة 103</span><span role="cell" class="ok">موثّق</span><span role="cell" class="ok">صادرة</span><span role="cell" class="bad">لا دفعة</span></div>
                    <div role="row"><span role="cell">محل 1</span><span role="cell" class="ok">موثّق</span><span role="cell" class="ok">صادرة</span><span role="cell" class="ok">مسددة</span></div>
                </div>
                <figcaption><b>صباح اليوم التالي:</b> شقة 103 في قائمة المراجعة، والباقي متطابق. <span>مثال توضيحي.</span></figcaption>
            </figure>
        </article>
    </div>
</section>

{{-- 5. Four windows on one record --}}
<section class="w2-roles" id="roles" aria-labelledby="roles-h">
    <div class="w2-wrap w2-roles-in">
        <div class="w2-roles-text">
            <h2 id="roles-h">أربع نوافذ على سجل واحد</h2>
            <p class="w2-roles-sub">يُثبَّت من المتصفح على الجوال دون متجر تطبيقات. الملاك والمستأجرون والفنيون دون رسوم على المكتب.</p>
            <div class="w2-tabs" role="tablist" aria-label="الواجهات">
                <button role="tab" id="t-office" aria-controls="p-office" aria-selected="true">المكتب</button>
                <button role="tab" id="t-owner" aria-controls="p-owner" aria-selected="false" tabindex="-1">المالك</button>
                <button role="tab" id="t-tenant" aria-controls="p-tenant" aria-selected="false" tabindex="-1">المستأجر</button>
                <button role="tab" id="t-tech" aria-controls="p-tech" aria-selected="false" tabindex="-1">الفني</button>
            </div>
            <div class="w2-panel" role="tabpanel" id="p-office" aria-labelledby="t-office">
                <p>صندوق مهام بما ينتظر قرارك، والبلاغات الجديدة، والمتأخرات، والعقود التي اقترب موعد إشعارها.</p>
                <ul><li>شجرة العقارات والمباني والوحدات، وتقويم الإتاحة</li><li>عرض تأجير يمر على اعتماداتك ثم رابط يقبله المستأجر</li><li>نقل بياناتك القديمة من ملف Excel أو من عقود إيجار</li><li>حصر كل موظف في العقارات التي يتولاها</li></ul>
            </div>
            <div class="w2-panel" role="tabpanel" id="p-owner" aria-labelledby="t-owner" hidden>
                <p>عقاراته وإشغالها وتحصيلها، وكشف حسابه الشهري، والموافقة على الصرف فوق السقف من جواله.</p>
                <ul><li>كشف يفصّل الإيجار والأتعاب والصيانة وصافي ما يُحوَّل</li><li>طلبات الموافقة على الصيانة بتقديرها وصورها</li><li>تغيير قاعدة الموافقة بعقد إدارة جديد يحفظ القديم</li></ul>
            </div>
            <div class="w2-panel" role="tabpanel" id="p-tenant" aria-labelledby="t-tenant" hidden>
                <p>عقده وفواتيره في مكان واحد، وموعد الإشعار قبل التجديد، وبلاغ العطل بصورة أو مقطع.</p>
                <ul><li>تذكير بالدفعة قبل موعدها وعنده</li><li>الأصول المسجلة على وحدته وحالتها عند الاستلام</li><li>التعاميم من إدارة المبنى بالصور والملفات</li></ul>
            </div>
            <div class="w2-panel" role="tabpanel" id="p-tech" aria-labelledby="t-tech" hidden>
                <p>أوامره لليوم، وباركود الأصل ليعرف تاريخه، وصور قبل العمل وبعده ولو انقطع الإنترنت.</p>
                <ul><li>بطاقة لكل أمر بموقعه ووصفه وصور البلاغ</li><li>تسجيل القطع والوقت والتكلفة الفعلية</li><li>مزامنة تلقائية عند عودة الاتصال</li></ul>
            </div>
        </div>
        <div class="w2-roles-stage" aria-live="polite">
            <figure class="w2-device w2-device-desk" data-for="p-office"><img src="{{ asset('site/v2/shots/facade.webp') }}" width="1100" height="857" alt="واجهة المبنى في شاشة المكتب: كل وحدة بلون حالتها" loading="lazy" decoding="async"></figure>
            <figure class="w2-device w2-device-desk" data-for="p-owner" hidden><img src="{{ asset('site/v2/shots/statement.webp') }}" width="1000" height="855" alt="كشف حساب المالك في بوابته" loading="lazy" decoding="async"></figure>
            <figure class="w2-device w2-device-phone" data-for="p-tenant" hidden><img src="{{ asset('site/v2/shots/tenant.webp') }}" width="720" height="1551" alt="بوابة المستأجر على الجوال: وحدته وعقده وموعد الإشعار والأصول المسجلة" loading="lazy" decoding="async"></figure>
            <figure class="w2-device w2-device-phone" data-for="p-tech" hidden><img src="{{ asset('site/v2/shots/tech.webp') }}" width="720" height="1551" alt="تطبيق الفني على الجوال: أمر العمل المسند إليه وتسجيل التكلفة وصور بعد الإنجاز" loading="lazy" decoding="async"></figure>
        </div>
    </div>
</section>

{{-- 6. The building's QR + what we never do --}}
<section class="w2-pair" aria-label="باركود المبنى وحدود المنصة">
    <div class="w2-wrap w2-pair-in">
        <div class="w2-qr">
            <h2>واجهة المبنى تستقطب مستأجره القادم</h2>
            <p>لوحة باركود على مدخل المبنى. من يمسحها يرى أنواع الوحدات ويسجّل في قائمة الانتظار مجاناً، فتعرف قبل أن تشغر الوحدة كم مهتماً ينتظرها.</p>
            <ol class="w2-flow">
                <li>يمسح المار الباركود</li>
                <li>يسجّل اهتمامه بموافقته</li>
                <li>تصلك القائمة وتنبيه حين تطول</li>
            </ol>
        </div>
        <div class="w2-never">
            <h2>ما لا تفعله المنصة</h2>
            <ul>
                <li><b>لا تستلم أموالاً.</b> الإيجار في حساب المالك، أو في أمانات المكتب حين يسمح عقد الإدارة.</li>
                <li><b>لا يرى مكتب بيانات مكتب آخر.</b> العزل في قاعدة البيانات نفسها، لا في الشاشات فقط.</li>
                <li><b>لا يُحذف قيد مالي.</b> كل إجراء في سجل تدقيق لا يُعدَّل.</li>
                <li><b>لا تنشر باسمك.</b> التوثيق في إيجار يبقى بيدك.</li>
            </ul>
        </div>
    </div>
</section>

{{-- 7. Plans --}}
<section class="w2-plans" id="plans" aria-labelledby="plans-h">
    <div class="w2-wrap">
        <div class="w2-sec-head">
            <h2 id="plans-h">الباقات</h2>
            <p>التسعير بعدد الوحدات لا بعدد المستخدمين، والصيانة وبوابة المستأجر وسجل الأصول في كل باقة. الأسعار تُعلن عند الإطلاق.</p>
        </div>
        <div class="w2-table-wrap" tabindex="0" aria-label="جدول مقارنة الباقات، يمكن تمريره أفقياً">
            <table class="w2-table">
                <caption class="w2-sr">مقارنة الباقات</caption>
                <thead><tr><th scope="col"><span class="w2-sr">الميزة</span></th><th scope="col">مالك</th><th scope="col">مكتب</th><th scope="col">شركة</th></tr></thead>
                <tbody>
                    <tr><th scope="row">عدد الوحدات</th><td>حتى 20</td><td>حتى 300</td><td>غير محدود</td></tr>
                    <tr><th scope="row">المستخدمون</th><td>غير محدودين</td><td>غير محدودين</td><td>غير محدودين</td></tr>
                    <tr><th scope="row">الصيانة وسجل الأصول وجرد التسليم</th><td><span class="w2-yes" aria-label="متاح">●</span></td><td><span class="w2-yes" aria-label="متاح">●</span></td><td><span class="w2-yes" aria-label="متاح">●</span></td></tr>
                    <tr><th scope="row">بوابة المستأجر وبوابة المالك</th><td><span class="w2-yes" aria-label="متاح">●</span></td><td><span class="w2-yes" aria-label="متاح">●</span></td><td><span class="w2-yes" aria-label="متاح">●</span></td></tr>
                    <tr><th scope="row">محاسبة الأمانات وتعدد الملاك والموردون</th><td><span class="w2-no" aria-label="غير متاح">—</span></td><td><span class="w2-yes" aria-label="متاح">●</span></td><td><span class="w2-yes" aria-label="متاح">●</span></td></tr>
                    <tr><th scope="row">هوية المكتب</th><td>الشعار</td><td>الشعار واللون</td><td>هوية كاملة ونطاق خاص</td></tr>
                    <tr><th scope="row">الربط مع برامج المحاسبة</th><td><span class="w2-no" aria-label="غير متاح">—</span></td><td>تصدير القيود</td><td><span class="w2-yes" aria-label="متاح">●</span></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- 8. Questions --}}
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

{{-- 9. Demo request --}}
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
    <div class="w2-wrap w2-foot-in">
        <span class="w2-foot-logo" aria-hidden="true">{!! $svg('withaq-stacked') !!}</span>
        <p>مزوّد برمجيات لإدارة الأملاك في المملكة العربية السعودية. لا نستلم أموالاً ولا ندير العقارات بأنفسنا.</p>
        <a class="w2-textlink w2-textlink-light" href="{{ $loginUrl }}">دخول المكاتب والملاك</a>
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

    // the roles: tabs switch the text and the real screen beside it (arrow keys move between tabs, RTL)
    const tabs = [...document.querySelectorAll('.w2-tabs [role=tab]')];
    const shots = [...document.querySelectorAll('.w2-roles-stage [data-for]')];
    const select = (t, focus = true) => {
        tabs.forEach((x) => { const on = x === t; x.setAttribute('aria-selected', on); x.tabIndex = on ? 0 : -1; document.getElementById(x.getAttribute('aria-controls')).hidden = !on; });
        shots.forEach((s) => { s.hidden = s.dataset.for !== t.getAttribute('aria-controls'); });
        if (focus) t.focus();
    };
    tabs.forEach((t, i) => {
        t.addEventListener('click', () => select(t));
        t.addEventListener('keydown', (e) => {
            const d = e.key === 'ArrowLeft' ? 1 : e.key === 'ArrowRight' ? -1 : 0;
            if (d) { e.preventDefault(); select(tabs[(i + d + tabs.length) % tabs.length]); }
        });
    });

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
