<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>عرض تأجير</title>
<style>body{font-family:system-ui,sans-serif;background:#FBF7F2;color:#2A1E17;margin:0}main{max-width:640px;margin:0 auto;padding:16px}.card{background:#fff;border:1px solid #E8DDD2;border-radius:10px;padding:16px}button{padding:10px 16px;margin-top:12px}</style></head>
<body><main><h1>عرض تأجير</h1>
<div class="card">
<p>الوحدة: {{ $offer->unit->code }} — {{ $offer->unit->name_ar }}</p>
<p>تبدأ في: {{ $offer->starts_on->toDateString() }} لمدة {{ $offer->duration_count }} {{ $offer->duration_unit === 'year' ? 'سنة' : 'شهر' }}</p>
<p>الإيجار السنوي: {{ $offer->annual_rent_minor->toDecimalString() }} ر.س — التأمين: {{ $offer->deposit_minor->toDecimalString() }} ر.س</p>
@if ($errors->any())<p>{{ $errors->first() }}</p>@endif
<form method="post" action="{{ route('offer.accept.post', $token) }}">@csrf<button type="submit">أقبل العرض</button></form>
</div></main></body></html>
