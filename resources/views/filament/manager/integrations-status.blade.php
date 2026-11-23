@php
    $hint = [
        'ejar' => 'يجهّز النظام بيانات العقد وتوثّقه أنت في إيجار، ثم تُدخل رقمه. الربط الآلي بعد قبول المنصة في برنامج إيجار.',
        'none' => 'لم يُختر المزوّد بعد، فلا يُرسل شيء من هذا النوع.',
        'simulator' => 'بيئة تجربة: لا يصل شيء إلى الجهة الفعلية.',
        'live' => 'الربط فعّال.',
    ];
@endphp
<ul class="wq-integ">
    @foreach ($rows as $r)
        <li class="wq-integ-row">
            <span class="wq-integ-name"><strong>{{ $r['name'] }}</strong><small>{{ $r['purpose'] }}</small></span>
            <span class="wq-integ-state wq-integ-{{ $r['color'] }}">{{ $r['state_ar'] }}</span>
            <span class="wq-integ-note">
                {{ $r['key'] === 'ejar' && $r['state'] === 'manual' ? $hint['ejar'] : $hint[$r['state']] }}
                @if ($r['account'])<br>الحساب باسم: {{ $r['account'] }}@endif
            </span>
        </li>
    @endforeach
</ul>
