<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page['building_ar'] }} — {{ $page['publisher_ar'] }}</title>
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}"><style>{!! app(\App\Theming\ThemeService::class)->css() !!} body{font-family:'Tajawal','Cairo',sans-serif;background:var(--color-bg)!important;color:var(--color-ink)!important} h1,h2{font-family:'Cairo',sans-serif;color:var(--color-deep,var(--color-ink))} button{background:var(--color-primary);color:var(--color-on-primary,#fff);border:0;border-radius:10px;font-weight:700;cursor:pointer} .card{border-radius:14px!important;border-color:var(--color-line)!important;background:var(--color-surface)!important}</style>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #FBF7F2; color: #2A1E17; }
        main { max-width: 720px; margin: 0 auto; padding: 16px; }
        .license { font-weight: 700; padding: 8px 12px; border: 2px solid currentColor; display: inline-block; border-radius: 8px; }
        .card { background: #fff; border: 1px solid #E8DDD2; border-radius: 10px; padding: 12px; margin: 8px 0; }
        .vacant { background: #FBEED3; color: #734F0E; padding: 2px 8px; border-radius: 6px; }
        .soon { background: #E5E2F2; color: #463C7A; padding: 2px 8px; border-radius: 6px; }
        label { display: block; margin-top: 8px; } input, select { width: 100%; padding: 8px; box-sizing: border-box; }
        button { margin-top: 12px; padding: 10px 16px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $page['building_ar'] }}</h1>
    {{-- Published under the subscriber's name and licence, not the platform's (REQ-0452, REQ-0389) --}}
    <p>{{ $page['publisher_ar'] }}</p>
    @if ($page['ad_license_no'])
        <p class="license">رقم ترخيص الإعلان العقاري: {{ $page['ad_license_no'] }}</p>
    @endif

    @if ($page['state'] === 'paused')
        <p>الصفحة متوقفة مؤقتاً.</p>
    @elseif ($page['state'] === 'no_valid_ad_license')
        <p>لا تُعرض وحدات حالياً.</p>
    @else
        <h2>الوحدات الشاغرة الآن</h2>
        @forelse ($page['vacant'] as $u)
            <div class="card"><span class="vacant">شاغر</span> {{ $u['code'] }} — {{ \App\Properties\UnitTypes::get($u['type'])['ar'] }}
                @if ($u['area_sqm']) — {{ $u['area_sqm'] }} م² @endif
                @if ($u['annual_price']) — {{ $u['annual_price'] }} ر.س سنوياً @endif
            </div>
        @empty
            <p>لا توجد وحدات شاغرة الآن.</p>
        @endforelse

        <h2>وحدات ستشغر</h2>
        @forelse ($page['upcoming'] as $u)
            <div class="card"><span class="soon">سيشغر</span> {{ $u['code'] }} — {{ \App\Properties\UnitTypes::get($u['type'])['ar'] }} — متاحة من شهر {{ $u['available_from_month'] }}</div>
        @empty
            <p>لا توجد وحدات معلنة ستشغر.</p>
        @endforelse

        <h2>سجّلني كمهتم</h2>
        @if (session('registered'))<p>تم تسجيلك. سنطلب منك تأكيد اهتمامك دورياً.</p>@endif
        @if ($errors->any())<p>{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ route('building.register', $code) }}">
            @csrf
            <label>الاسم <input name="name" required maxlength="120"></label>
            <label>الجوال <input name="phone" required maxlength="30"></label>
            <label>البريد الإلكتروني <input name="email" type="email"></label>
            <label>نوع الوحدة <input name="unit_type" maxlength="30"></label>
            <label>الميزانية السنوية (ر.س) <input name="budget" inputmode="decimal"></label>
            <label>الشهر المطلوب <input name="desired_month" type="month"></label>
            <label><input type="checkbox" name="consent" value="1" required style="width:auto">
                أوافق على استخدام بياناتي للتواصل بشأن الوحدات في هذا المبنى فقط، وأعلم أنها تُحذف إن لم أؤكد اهتمامي دورياً، ولي حق طلب حذفها.</label>
            <button type="submit">تسجيل مجاني</button>
        </form>
    @endif
</main>
</body>
</html>
