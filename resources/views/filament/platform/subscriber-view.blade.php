@php
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money($m);
    $stateAr = \App\Plans\SubscriberAdmin::STATE_AR[$state];
    $roles = collect($usage['users_by_role']);
    $roleAr = fn (string $r) => \App\Authorization\Role::from($r)->labelAr();
    $pct = $usage['max_units'] ? min(100, intdiv($usage['units'] * 100, max(1, $usage['max_units']))) : null;
    $channels = ['whatsapp' => 'واتساب', 'sms' => 'رسائل نصية', 'email' => 'بريد', 'push' => 'تنبيهات', 'in_app' => 'داخل المنصة'];
@endphp
<x-filament-panels::page class="wq-portal wq-sub">
    {{-- The one number the admin opens this page for: how long is left --}}
    <section class="wq-sub-hero wq-sub-{{ $state }}" aria-label="حالة الاشتراك">
        <div>
            <span class="wq-sub-state">{{ $stateAr }}</span>
            <h2 class="wq-sub-plan">باقة {{ $plan['ar'] ?? $s->plan }}@if ($s->billing_cycle) · {{ \App\Models\Subscriber::CYCLES[$s->billing_cycle] }}@endif</h2>
            @if ($s->isSuspended())
                <p class="wq-sub-note">أُوقف {{ $s->suspended_at?->format('Y-m-d') }}: {{ $s->suspended_reason }}</p>
            @endif
        </div>
        <div class="wq-sub-days">
            @if ($days === null)
                <strong>—</strong><span>لم تُسجَّل مدة للاشتراك. أضفها من «تعديل البيانات».</span>
            @elseif ($state === 'grace')
                <strong>{{ $graceLeft }}</strong><span>{{ $graceLeft === 0 ? 'اليوم آخر أيام مهلة السماح' : 'يوماً متبقية من مهلة السماح' }} · انتهى الاشتراك {{ $s->subscription_ends_on->format('Y-m-d') }}، ثم قراءة فقط تلقائياً</span>
            @elseif ($days < 0)
                <strong>{{ abs($days) }}</strong><span>يوماً منذ انتهاء الاشتراك ({{ $s->subscription_ends_on->format('Y-m-d') }}) · المكتب للقراءة فقط منذ انتهاء المهلة</span>
            @else
                <strong>{{ $days }}</strong><span>يوماً متبقية · ينتهي {{ $s->subscription_ends_on->format('Y-m-d') }}</span>
            @endif
        </div>
    </section>

    <div class="wq-grid">
        <section class="wq-panel" aria-labelledby="sub-h">
            <header class="wq-panel-head"><h2 id="sub-h">الاشتراك</h2></header>
            <dl class="wq-sub-facts">
                <div><dt>البداية</dt><dd>{{ $s->subscription_starts_on?->format('Y-m-d') ?? '—' }}</dd></div>
                <div><dt>النهاية</dt><dd>{{ $s->subscription_ends_on?->format('Y-m-d') ?? '—' }}</dd></div>
                <div><dt>السعر المتفق عليه</dt><dd>{{ $s->price_minor ? $money($s->price_minor) . ($s->billing_cycle ? ' / ' . ($s->billing_cycle === 'annual' ? 'سنة' : 'شهر') : '') : 'لم يُحدَّد' }}</dd></div>
                <div><dt>مهلة السماح بعد الانتهاء</dt><dd>{{ $graceDays }} أيام {{ $s->grace_days !== null ? '(ممدّدة لهذا المشترك)' : '(الافتراضي)' }}</dd></div>
                <div><dt>مشترك منذ</dt><dd>{{ $s->created_at->format('Y-m-d') }}</dd></div>
                @if ($s->notes)<div class="wq-sub-wide"><dt>ملاحظات</dt><dd>{{ $s->notes }}</dd></div>@endif
            </dl>
            @php($addonIcons = ['qr_boards' => 'heroicon-o-qr-code', 'asset_qr_labels' => 'heroicon-o-tag', 'waitlist' => 'heroicon-o-queue-list', 'extra_whatsapp_messages' => 'heroicon-o-chat-bubble-left-right'])
            <div class="wq-addons" role="list" aria-label="الإضافات">
                @foreach (\App\Filament\Platform\Resources\Subscribers\SubscriberResource::ADDONS_AR as $key => $label)
                    @php($on = in_array($key, $addons, true))
                    <div class="wq-addon {{ $on ? 'is-on' : '' }}" role="listitem">
                        <x-filament::icon :icon="$addonIcons[$key] ?? 'heroicon-o-puzzle-piece'" class="wq-addon-icon" />
                        <span class="wq-addon-name">{{ $label }}</span>
                        <span class="wq-addon-state">{{ $on ? 'مشترك' : 'غير مشترك' }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="wq-panel" aria-labelledby="use-h">
            <header class="wq-panel-head"><h2 id="use-h">الاستخدام</h2><span class="wq-panel-meta">أعداد فقط؛ بيانات المكتب لا تظهر للمنصة</span></header>
            <div class="wq-sub-usage">
                <div class="wq-sub-meter">
                    <div class="wq-sub-meter-top"><span>الوحدات</span><strong>{{ $usage['units'] }} من {{ $usage['max_units'] ?? 'غير محدود' }}</strong></div>
                    @if ($pct !== null)
                        <div class="wq-sub-bar {{ $pct >= 90 ? 'is-high' : '' }}" role="progressbar" aria-valuenow="{{ $usage['units'] }}" aria-valuemin="0" aria-valuemax="{{ $usage['max_units'] }}" aria-label="الوحدات من حد الباقة"><span style="--p: {{ $pct }}%"></span></div>
                    @endif
                </div>
                <dl class="wq-sub-facts">
                    <div><dt>العقارات</dt><dd>{{ $usage['properties'] }}</dd></div>
                    <div><dt>عقود سارية</dt><dd>{{ $usage['active_leases'] }}</dd></div>
                    <div><dt>المستخدمون النشطون</dt><dd>{{ $usage['users'] }}</dd></div>
                    <div><dt>الرسائل هذا الشهر</dt><dd>{{ collect($usage['messages_this_month'])->map(fn ($n, $c) => ($channels[$c] ?? $c) . ' ' . $n)->implode('، ') ?: '0' }}</dd></div>
                </dl>
                @if ($roles->isNotEmpty())
                    <p class="wq-sub-roles">@foreach ($roles as $r => $n)<span class="wq-chip">{{ $roleAr($r) }} {{ $n }}</span>@endforeach</p>
                @endif
            </div>
        </section>

        <section class="wq-panel" aria-labelledby="off-h">
            <header class="wq-panel-head"><h2 id="off-h">بيانات المكتب</h2></header>
            <dl class="wq-sub-facts">
                <div><dt>الاسم بالإنجليزية</dt><dd>{{ $s->name_en }}</dd></div>
                <div><dt>السجل التجاري</dt><dd>{{ $s->commercial_registration ?? '—' }}</dd></div>
                <div><dt>الرقم الضريبي</dt><dd>{{ $s->vat_number ?? '—' }}</dd></div>
                <div><dt>المدينة</dt><dd>{{ $s->city ?? '—' }}</dd></div>
                <div><dt>مسؤول التواصل</dt><dd>{{ $s->contact_name ?? '—' }}</dd></div>
                <div><dt>الجوال</dt><dd dir="ltr">{{ $s->contact_phone ?? '—' }}</dd></div>
                <div class="wq-sub-wide"><dt>البريد</dt><dd dir="ltr">{{ $s->contact_email ?? '—' }}</dd></div>
            </dl>
        </section>
    </div>

    <section class="wq-panel" aria-labelledby="usr-h">
        <header class="wq-panel-head"><h2 id="usr-h">الهيكل التنظيمي للمكتب</h2><span class="wq-panel-meta">{{ $team['count'] }} موظفين · كل إجراء يُسجَّل في سجل تدقيق المكتب</span></header>
        @include('filament.partials.team-chart', ['tree' => $team, 'mode' => 'platform'])
    </section>
</x-filament-panels::page>
