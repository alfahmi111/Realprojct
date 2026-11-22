@php
    $dates = app(\App\Support\Dates\DateService::class);
    $now = \Carbon\CarbonImmutable::now();
    $tz = 'Asia/Riyadh';
    $day = \IntlDateFormatter::formatObject($now->setTimezone($tz), 'EEEE', 'ar_SA');
    $hijri = $dates->format($now, 'hijri', $tz);
    $greg = $dates->format($now, 'gregorian', $tz);
@endphp
{{-- Identity panel beside the sign-in card (layout of the owner's design; no third-party logos). --}}
<aside class="wq-auth-aside" aria-label="وِثاق">
    <div class="wq-auth-mark">{!! file_get_contents(public_path('brand/withaq-mark.svg')) !!}</div>
    <div class="wq-auth-kicker">منصة إدارة الأملاك والأصول العقارية</div>
    <h1 class="wq-auth-title">وِثاق</h1>
    <p class="wq-auth-sub">التأجير والصيانة والأصول والمحاسبة في منصة واحدة</p>
    <div class="wq-auth-today">
        <span class="wq-today-day">{{ $day }}</span>
        <span class="wq-today-hijri">{{ $hijri }}</span>
        <span class="wq-today-greg">{{ $greg }}</span>
    </div>
</aside>
