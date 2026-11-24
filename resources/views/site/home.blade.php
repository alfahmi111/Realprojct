@php
    // The facade in the hero: an illustrative building in the platform's five fixed unit colours (REQ-0330).
    $st = [
        'paid' => 'مؤجرة ومسددة', 'late' => 'عليها متأخرات', 'vacant' => 'شاغرة', 'maint' => 'في الصيانة', 'soon' => 'ستُخلى قريباً',
    ];
    $floors = [
        ['name' => 'الدور الرابع', 'units' => [['401', 'paid', 'عقد حتى رمضان 1448'], ['402', 'paid', 'عقد حتى ذي الحجة 1448'], ['403', 'soon', 'أشعر المستأجر بعدم التجديد'], ['404', 'paid', 'عقد حتى شعبان 1449']]],
        ['name' => 'الدور الثالث', 'units' => [['301', 'late', 'دفعة متأخرة 12 يوماً'], ['302', 'paid', 'عقد حتى محرم 1449'], ['303', 'paid', 'عقد حتى صفر 1449'], ['304', 'vacant', 'في قائمة الانتظار 3 مهتمين']]],
        ['name' => 'الدور الثاني', 'units' => [['201', 'paid', 'عقد حتى رجب 1448'], ['202', 'maint', 'تجهيز قبل التسليم'], ['203', 'paid', 'عقد حتى شوال 1448'], ['204', 'paid', 'عقد حتى ربيع الأول 1449']]],
        ['name' => 'الدور الأول', 'units' => [['101', 'paid', 'عقد حتى جمادى الآخرة 1449'], ['102', 'paid', 'عقد حتى رمضان 1448'], ['103', 'late', 'فاتورة غير مسددة'], ['104', 'paid', 'عقد حتى ذي القعدة 1448']]],
    ];
    $shops = [['محل 1', 'paid', 'صيدلية، عقد تجاري حتى 1450'], ['محل 2', 'vacant', 'شاغر منذ 20 يوماً'], ['محل 3', 'paid', 'مغسلة، عقد حتى 1449']];
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>وِثاق | إدارة الأملاك والصيانة والأصول للمكاتب العقارية</title>
    <meta name="description" content="منصة سعودية لمكاتب إدارة الأملاك: العقود والتحصيل وكشف حساب المالك، والصيانة وسجل أصول كل وحدة، وبوابات للمالك والمستأجر والفني، مع قواعد إيجار والضريبة مطبّقة.">
    <meta name="theme-color" content="#0D4F5C">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('brand/withaq-favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('brand/icons/apple-touch-icon.png') }}">
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('site/site.css') }}?v={{ @filemtime(public_path('site/site.css')) }}">
</head>
<body class="ws">
<a class="ws-skip" href="#main">انتقل إلى المحتوى</a>

<header class="ws-head">
    <div class="ws-wrap ws-head-in">
        <a class="ws-brand" href="{{ route('site.home') }}" aria-label="وِثاق، الصفحة الرئيسية">
            <span class="ws-logo" aria-hidden="true">{!! file_get_contents(public_path('brand/withaq-wordmark.svg')) !!}</span>
        </a>
        <nav class="ws-nav" aria-label="أقسام الصفحة">
            <a href="#what">ما تحصل عليه</a>
            <a href="#roles">لكل طرف واجهته</a>
            <a href="#plans">الباقات</a>
            <a href="#faq">أسئلة</a>
        </nav>
        <div class="ws-head-cta">
            <a class="ws-link" href="{{ $loginUrl }}">دخول</a>
            <a class="ws-btn ws-btn-sm" href="#demo">اطلب عرضاً</a>
        </div>
    </div>
</header>

<main id="main">
    {{-- Hero: the building facade, unit by unit --}}
    <section class="ws-hero">
        <div class="ws-wrap ws-hero-in">
            <div class="ws-hero-text">
                <h1>كل وحدة،<br>بكل ما فيها.</h1>
                <p class="ws-lead">منصة لمكاتب إدارة الأملاك والملاك المؤسسيين: العقد والتحصيل، وما في الوحدة من أصول وحالتها، ومن سكنها، وكم أدرّت وكم كلّفت. ويصل كل مالك إلى كشف حساب يفصّل كل ريال.</p>
                <div class="ws-hero-cta">
                    <a class="ws-btn" href="#demo">اطلب عرضاً لمكتبك</a>
                    <a class="ws-btn ws-btn-ghost" href="#what">ماذا يفعل النظام؟</a>
                </div>
                <ul class="ws-legend" aria-label="ألوان حالة الوحدات">
                    @foreach ($st as $k => $label)
                        <li><span class="ws-dot ws-s-{{ $k }}" aria-hidden="true"></span>{{ $label }}</li>
                    @endforeach
                </ul>
            </div>

            <figure class="ws-building" aria-label="مثال لواجهة مبنى في النظام: كل نافذة وحدة بلون حالتها اليوم">
                <div class="ws-roof" aria-hidden="true"><span>عمارة النخيل · حي الياسمين</span></div>
                <div class="ws-floors" role="list">
                    @foreach ($floors as $f)
                        <div class="ws-floor" role="listitem">
                            <span class="ws-floor-name">{{ $f['name'] }}</span>
                            <div class="ws-windows">
                                @foreach ($f['units'] as [$code, $s, $note])
                                    <button type="button" class="ws-win ws-s-{{ $s }}" data-unit="شقة {{ $code }}" data-state="{{ $st[$s] }}" data-note="{{ $note }}" aria-label="شقة {{ $code }}: {{ $st[$s] }}، {{ $note }}">{{ $code }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <div class="ws-floor ws-ground" role="listitem">
                        <span class="ws-floor-name">الأرضي</span>
                        <div class="ws-windows ws-shops">
                            @foreach ($shops as [$code, $s, $note])
                                <button type="button" class="ws-win ws-shop ws-s-{{ $s }}" data-unit="{{ $code }}" data-state="{{ $st[$s] }}" data-note="{{ $note }}" aria-label="{{ $code }}: {{ $st[$s] }}، {{ $note }}">{{ $code }}</button>
                            @endforeach
                            <span class="ws-door" aria-hidden="true"><span class="ws-qr" title="باركود المبنى"></span></span>
                        </div>
                    </div>
                </div>
                <figcaption class="ws-caption" aria-live="polite">
                    <strong class="ws-cap-unit">مرّر على أي نافذة</strong>
                    <span class="ws-cap-state">هكذا يرى المكتب مبناه كل صباح: 19 وحدة، لكل منها حالتها اليوم.</span>
                </figcaption>
            </figure>
        </div>
    </section>

    {{-- Three promises --}}
    <section class="ws-sec" id="what" aria-labelledby="what-h">
        <div class="ws-wrap">
            <h2 id="what-h">ثلاثة أشياء تعرفها دون أن تسأل أحداً</h2>
            <div class="ws-promises">
                <article>
                    <h3>تعرف ما تملك</h3>
                    <p>لكل وحدة سجل أصول بالصورة: المكيف والسخان والمطبخ، بتاريخ تركيبها وضمانها وكم صُرف عليها. يُجرد عند التسليم والاستلام، فيظهر ما تغيّر.</p>
                    <ul>
                        <li>صيانة تصحيحية ووقائية وطارئة بأوامر عمل</li>
                        <li>تطبيق للفني يعمل دون إنترنت ويرفع الصور</li>
                        <li>سقف صرف لا يُتجاوز دون موافقة المالك</li>
                    </ul>
                </article>
                <article>
                    <h3>تعرف أين ذهب مالك</h3>
                    <p>كشف شهري لكل مالك: ما حُصّل، وما دُفع له مباشرة، وأتعاب المكتب بنص عقد الإدارة، وكل مصروف صيانة برقم أمره.</p>
                    <ul>
                        <li>حساب أمانات منفصل لكل مالك</li>
                        <li>إيداع الإيجار في حساب المالك افتراضياً</li>
                        <li>موافقة شخصين على الإلغاء والصرف والرد</li>
                    </ul>
                </article>
                <article>
                    <h3>تبقى نظامياً دون أن تتابع الأنظمة</h3>
                    <p>قواعد مثل إيقاف زيادة الإيجار في الرياض وأسباب رفض التجديد محفوظة في جدول يُحدَّث، فيمنع النظام الخطأ قبل وقوعه.</p>
                    <ul>
                        <li>تجهيز بيانات التوثيق في إيجار ومتابعتها</li>
                        <li>فواتير بالحقول التي تطلبها هيئة الزكاة</li>
                        <li>تسوية ليلية تكشف ما خرج عن التطابق</li>
                    </ul>
                </article>
            </div>
        </div>
    </section>

    {{-- Roles: one switcher, four interfaces --}}
    <section class="ws-sec ws-sec-mist" id="roles" aria-labelledby="roles-h">
        <div class="ws-wrap ws-roles">
            <div>
                <h2 id="roles-h">لكل طرف واجهته، في تطبيق واحد</h2>
                <p class="ws-sub">يُثبَّت من المتصفح على الجوال دون متجر تطبيقات. الملاك والمستأجرون والفنيون دون رسوم على المكتب.</p>
            </div>
            <div>
            <div class="ws-tabs" role="tablist" aria-label="الواجهات">
                <button role="tab" id="t-office" aria-controls="p-office" aria-selected="true">المكتب</button>
                <button role="tab" id="t-owner" aria-controls="p-owner" aria-selected="false" tabindex="-1">المالك</button>
                <button role="tab" id="t-tenant" aria-controls="p-tenant" aria-selected="false" tabindex="-1">المستأجر</button>
                <button role="tab" id="t-tech" aria-controls="p-tech" aria-selected="false" tabindex="-1">الفني</button>
            </div>
            <div class="ws-panel" role="tabpanel" id="p-office" aria-labelledby="t-office">
                <p class="ws-panel-lead">صباح المدير يبدأ بصندوق مهام: ما ينتظر قراره، والبلاغات الجديدة، والمتأخرات، والعقود التي اقترب موعد إشعارها.</p>
                <ul class="ws-ticks">
                    <li>شجرة العقارات والمباني والوحدات، وتقويم الإتاحة لكل العقارات</li>
                    <li>عرض تأجير يمر على اعتماداتك ثم رابط يقبله المستأجر</li>
                    <li>التجديد التلقائي للعقد عند انتهائه دون إشعار، مع إمكان إنهائه</li>
                    <li>نقل بياناتك القديمة من ملف Excel أو من عقود إيجار</li>
                    <li>حصر كل موظف في العقارات التي يتولاها</li>
                </ul>
            </div>
            <div class="ws-panel" role="tabpanel" id="p-owner" aria-labelledby="t-owner" hidden>
                <p class="ws-panel-lead">يرى المالك عقاراته وإشغالها وتحصيلها، وكشف حسابه الشهري، ويوافق على الصرف فوق السقف من جواله.</p>
                <ul class="ws-ticks">
                    <li>كشف يفصّل الإيجار والأتعاب والصيانة وصافي ما يُحوَّل</li>
                    <li>طلبات الموافقة على صرف الصيانة بتقديرها وصورها</li>
                    <li>تغيير قاعدة الموافقة بعقد إدارة جديد يحفظ القديم</li>
                </ul>
            </div>
            <div class="ws-panel" role="tabpanel" id="p-tenant" aria-labelledby="t-tenant" hidden>
                <p class="ws-panel-lead">يعرف المستأجر إلى من يتوجه: فواتيره وعقده في مكان واحد، وبلاغ العطل بصورة أو مقطع فيديو.</p>
                <ul class="ws-ticks">
                    <li>تذكير بالدفعة قبل موعدها وعنده</li>
                    <li>متابعة البلاغ حتى إغلاقه وتقييم العمل</li>
                    <li>التعاميم من إدارة المبنى بالصور والملفات</li>
                </ul>
            </div>
            <div class="ws-panel" role="tabpanel" id="p-tech" aria-labelledby="t-tech" hidden>
                <p class="ws-panel-lead">يرى الفني أوامره لليوم، ويمسح باركود الأصل ليعرف تاريخه، ويرفع صور قبل العمل وبعده ولو انقطع الإنترنت.</p>
                <ul class="ws-ticks">
                    <li>بطاقة لكل أمر بموقعه ووصفه وصور البلاغ</li>
                    <li>تسجيل التكلفة الفعلية عند الإنهاء</li>
                    <li>مزامنة تلقائية عند عودة الاتصال</li>
                </ul>
            </div>
            </div>
        </div>
    </section>

    {{-- Building QR --}}
    <section class="ws-sec" aria-labelledby="qr-h">
        <div class="ws-wrap ws-split">
            <div>
                <h2 id="qr-h">واجهة المبنى تستقطب مستأجره القادم</h2>
                <p>لوحة باركود على مدخل المبنى. من يمسحها يرى أنواع الوحدات ويسجّل في قائمة الانتظار مجاناً، فتعرف قبل أن تشغر الوحدة كم مهتماً ينتظرها.</p>
            </div>
            <ul class="ws-steps">
                <li><strong>يمسح المار الباركود</strong><span>صفحة المبنى العامة بشعار مكتبك</span></li>
                <li><strong>يسجّل اهتمامه</strong><span>نوع الوحدة والميزانية، بموافقته الصريحة</span></li>
                <li><strong>تصلك القائمة</strong><span>وتنبيه حين تطول القائمة والوحدة شاغرة</span></li>
            </ul>
        </div>
    </section>

    {{-- Plans: a comparison table, not three identical cards --}}
    <section class="ws-sec ws-sec-mist" id="plans" aria-labelledby="plans-h">
        <div class="ws-wrap">
            <h2 id="plans-h">الباقات</h2>
            <p class="ws-sub">التسعير بعدد الوحدات لا بعدد المستخدمين، والصيانة وبوابة المستأجر وسجل الأصول في كل باقة. الأسعار تُعلن عند الإطلاق.</p>
            <div class="ws-table-wrap">
                <table class="ws-table">
                    <caption class="ws-sr">مقارنة الباقات</caption>
                    <thead>
                        <tr><th scope="col"><span class="ws-sr">الميزة</span></th><th scope="col">مالك</th><th scope="col">مكتب</th><th scope="col">شركة</th></tr>
                    </thead>
                    <tbody>
                        <tr><th scope="row">عدد الوحدات</th><td>حتى 20</td><td>حتى 300</td><td>غير محدود</td></tr>
                        <tr><th scope="row">المستخدمون</th><td>غير محدودين</td><td>غير محدودين</td><td>غير محدودين</td></tr>
                        <tr><th scope="row">الصيانة وسجل الأصول وجرد التسليم</th><td>✓</td><td>✓</td><td>✓</td></tr>
                        <tr><th scope="row">بوابة المستأجر وبوابة المالك</th><td>✓</td><td>✓</td><td>✓</td></tr>
                        <tr><th scope="row">محاسبة الأمانات وتعدد الملاك والموردون</th><td>—</td><td>✓</td><td>✓</td></tr>
                        <tr><th scope="row">هوية المكتب</th><td>الشعار</td><td>الشعار واللون</td><td>هوية كاملة ونطاق خاص</td></tr>
                        <tr><th scope="row">الربط مع برامج المحاسبة</th><td>—</td><td>تصدير القيود</td><td>✓</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="ws-sec" id="faq" aria-labelledby="faq-h">
        <div class="ws-wrap ws-narrow">
            <h2 id="faq-h">أسئلة يطرحها المكتب قبل أن يبدأ</h2>
            <details>
                <summary>هل تمر أموال المستأجرين عبر المنصة؟</summary>
                <p>لا. المنصة لا تستلم أموالاً ولا تحتفظ بها. الإيجار يُودَع في حساب المالك، أو في حساب أمانات المكتب حين يسمح بذلك عقد الإدارة، والمنصة تسجّل وتطابق فقط.</p>
            </details>
            <details>
                <summary>هل تُوثَّق العقود في إيجار من داخل النظام؟</summary>
                <p>يجهّز النظام بيانات العقد كاملة بالشكل الذي تطلبه منصة إيجار، وتوثّقه أنت هناك ثم تُدخل رقمه، ويتابع النظام حالته. الربط الآلي المباشر يأتي بعد اعتماد المنصة في برنامج إيجار.</p>
            </details>
            <details>
                <summary>هل يرى مكتب آخر بياناتي؟</summary>
                <p>لا. بيانات كل مكتب معزولة على مستوى قاعدة البيانات نفسها، لا على مستوى الشاشات فقط، وكل إجراء مالي يُسجَّل في سجل تدقيق لا يُعدَّل ولا يُحذف.</p>
            </details>
            <details>
                <summary>عندي بيانات في ملفات Excel، هل أبدأ من الصفر؟</summary>
                <p>لا. نزّل قالب الانتقال السريع واملأه بعقودك، أو ارفع عقود إيجار، فيقرأها النظام ويعرضها عليك لتؤكدها قبل أن تُضاف.</p>
            </details>
            <details>
                <summary>هل التواريخ بالهجري؟</summary>
                <p>تختار طريقة العرض: هجري أولاً أو ميلادي أولاً أو كلاهما، والنظام يحفظها ميلادياً ليبقى الحساب دقيقاً.</p>
            </details>
        </div>
    </section>

    {{-- Demo request --}}
    <section class="ws-sec ws-demo" id="demo" aria-labelledby="demo-h">
        <div class="ws-wrap ws-split">
            <div>
                <h2 id="demo-h">شاهد مبناك في النظام</h2>
                <p>اترك بياناتك ونتواصل معك لعرض النظام على بيانات قريبة من محفظتك، ونساعدك في نقل عقودك الحالية.</p>
            </div>
            @if (session('demo_sent'))
                <div class="ws-sent" role="status">
                    <strong>وصل طلبك.</strong>
                    <p>نتواصل معك على رقم جوالك خلال يومي عمل.</p>
                </div>
            @else
                <form class="ws-form" method="post" action="{{ route('site.demo') }}" novalidate>
                    @csrf
                    <div class="ws-hp" aria-hidden="true"><label>موقعك <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <label>الاسم<input type="text" name="name" value="{{ old('name') }}" required autocomplete="name" @error('name') aria-invalid="true" aria-describedby="e-name" @enderror></label>
                    @error('name')<span class="ws-err" id="e-name">{{ $message }}</span>@enderror
                    <label>المكتب أو الشركة<input type="text" name="organization" value="{{ old('organization') }}" autocomplete="organization"></label>
                    <div class="ws-row">
                        <label>الجوال<input type="tel" name="phone" value="{{ old('phone') }}" required inputmode="tel" autocomplete="tel" placeholder="05xxxxxxxx" dir="ltr" @error('phone') aria-invalid="true" aria-describedby="e-phone" @enderror></label>
                        <label>المدينة<input type="text" name="city" value="{{ old('city') }}"></label>
                    </div>
                    @error('phone')<span class="ws-err" id="e-phone">{{ $message }}</span>@enderror
                    <div class="ws-row">
                        <label>البريد الإلكتروني (اختياري)<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" dir="ltr"></label>
                        <label>عدد الوحدات التي تديرها
                            <select name="units">
                                <option value="">اختر</option>
                                @foreach ($units as $k => $v)<option value="{{ $k }}" @selected(old('units') === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    @error('email')<span class="ws-err">{{ $message }}</span>@enderror
                    <label>ما الذي تريد أن تراه؟ (اختياري)<textarea name="message" rows="3" maxlength="1000">{{ old('message') }}</textarea></label>
                    <label class="ws-check"><input type="checkbox" name="consent" value="1" @checked(old('consent')) @error('consent') aria-invalid="true" @enderror> أوافق على استخدام بياناتي للتواصل معي بشأن هذا العرض فقط.</label>
                    @error('consent')<span class="ws-err">{{ $message }}</span>@enderror
                    <button type="submit" class="ws-btn">أرسل الطلب</button>
                </form>
            @endif
        </div>
    </section>
</main>

<footer class="ws-foot">
    <div class="ws-wrap ws-foot-in">
        <span class="ws-brand ws-brand-foot"><span class="ws-logo ws-logo-foot">{!! file_get_contents(public_path('brand/withaq-horizontal.svg')) !!}</span></span>
        <p>مزوّد برمجيات لإدارة الأملاك في المملكة العربية السعودية. لا نستلم أموالاً ولا ندير العقارات بأنفسنا.</p>
        <a class="ws-link" href="{{ $loginUrl }}">دخول المكاتب والملاك</a>
    </div>
</footer>

<script>
(() => {
    // the facade: hovering or focusing a window explains it
    const cap = document.querySelector('.ws-caption');
    const u = cap.querySelector('.ws-cap-unit'), s = cap.querySelector('.ws-cap-state');
    const show = (w) => { u.textContent = w.dataset.unit + ': ' + w.dataset.state; s.textContent = w.dataset.note; };
    document.querySelectorAll('.ws-win').forEach((w) => {
        w.addEventListener('mouseenter', () => show(w));
        w.addEventListener('focus', () => show(w));
        w.addEventListener('click', () => show(w));
    });
    // the roles tabs (arrow keys move between tabs, as the ARIA pattern expects)
    const tabs = [...document.querySelectorAll('.ws-tabs [role=tab]')];
    const select = (t) => {
        tabs.forEach((x) => { const on = x === t; x.setAttribute('aria-selected', on); x.tabIndex = on ? 0 : -1; document.getElementById(x.getAttribute('aria-controls')).hidden = !on; });
        t.focus();
    };
    tabs.forEach((t, i) => {
        t.addEventListener('click', () => select(t));
        t.addEventListener('keydown', (e) => {
            const d = e.key === 'ArrowLeft' ? 1 : e.key === 'ArrowRight' ? -1 : 0;   // RTL: left = next
            if (d) { e.preventDefault(); select(tabs[(i + d + tabs.length) % tabs.length]); }
        });
    });
})();
</script>
</body>
</html>
