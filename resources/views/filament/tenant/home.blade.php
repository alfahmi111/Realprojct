@php
    $sar = fn ($m) => \App\Support\Dates\NumberFormatter::money($m);
    $statusAr = \App\Filament\Manager\Resources\WorkOrders\WorkOrderResource::STATUS_AR;
    $tone = ['completed' => 'wq-s-paid', 'closed' => 'wq-s-paid', 'in_progress' => 'wq-s-maint', 'assigned' => 'wq-s-maint', 'confirmed' => 'wq-s-paid', 'reported' => 'wq-s-soon', 'triaged' => 'wq-s-soon', 'estimated' => 'wq-s-soon', 'awaiting_owner_approval' => 'wq-s-vacant', 'rejected' => 'wq-s-late'];
    $due = collect($invoices)->filter(fn ($i) => $i['outstanding']->isPositive());
@endphp
<x-filament-panels::page class="wq-portal">
    <section class="wq-hello" aria-label="ترحيب">
        <div class="wq-hello-who">
            <span class="wq-hello-avatar" aria-hidden="true">{{ mb_substr($header['name'], 0, 1) }}</span>
            <div>
                <h1 class="wq-hello-title">{{ $header['greeting'] }}، {{ $header['name'] }}</h1>
                <p class="wq-hello-sub">
                    @if (! $unit)
                        لا يوجد عقد ساري مرتبط بحسابك
                    @elseif ($due->count())
                        {{ $due->count() === 1 ? 'فاتورة واحدة مستحقة' : $due->count() . ' فواتير مستحقة' }}
                    @else
                        لا فواتير مستحقة عليك
                    @endif
                    <span class="wq-hello-role">{{ $header['role'] }}</span>
                </p>
            </div>
        </div>
        <div class="wq-hello-date">
            <span class="wq-hello-day">{{ $header['day'] }}</span>
            <strong class="wq-hello-hijri">{{ $header['hijri'] }}</strong>
            <span class="wq-hello-greg">{{ $header['gregorian'] }}</span>
        </div>
    </section>

    @if (! $unit)
        <section class="wq-panel"><div class="wq-empty">لا يوجد عقد ساري مرتبط بحسابك. إن كنت وقّعت عقداً حديثاً فتواصل مع مكتب الإدارة لربطه بحسابك.</div></section>
    @else
        <div class="wq-grid">
            {{-- My unit and my lease: one glance at where the contract stands --}}
            <section class="wq-panel" aria-labelledby="u-h">
                <header class="wq-panel-head"><h2 id="u-h">وحدتي وعقدي</h2>
                    <span class="wq-panel-meta">رقم عقد إيجار: <bdi>{{ $lease['ejar_contract_number'] ?? '—' }}</bdi></span></header>
                <div class="wq-body">
                    <div class="wq-unit-hero">
                        <span class="wq-unit-badge">{{ $unit['unit'] }}</span>
                        <div><strong>وحدتي: {{ $unit['unit'] }}</strong><div class="wq-muted">{{ \App\Properties\UnitTypes::get($unit['type'])['ar'] }}</div></div>
                    </div>
                    @if ($timeline)
                        <div class="wq-timeline" role="img" aria-label="مدة العقد: مضى {{ $timeline['today'] }}٪">
                            <span class="wq-timeline-done" style="--p:{{ $timeline['today'] }}%"></span>
                            <span class="wq-timeline-mark wq-up" style="--p:{{ $timeline['today'] }}%"><span>اليوم</span></span>
                            @if ($timeline['notice'] !== null)
                                <span class="wq-timeline-mark wq-s-late" style="--p:{{ $timeline['notice'] }}%"><span>آخر موعد للإشعار</span></span>
                            @endif
                        </div>
                    @endif
                    <div class="wq-facts">
                        <div class="wq-fact"><span>بداية العقد</span><strong>{{ $lease['starts_on'] }}</strong></div>
                        <div class="wq-fact"><span>ينتهي في</span><strong>{{ $lease['ends_on'] }}</strong></div>
                        <div class="wq-fact"><span>آخر موعد لإشعار عدم التجديد</span><strong>{{ $lease['notice_deadline'] }}</strong>
                            @if ($timeline && $timeline['days_to_notice'] !== null && $timeline['days_to_notice'] >= 0)<span>بعد {{ $timeline['days_to_notice'] }} يوماً</span>@endif</div>
                    </div>
                    <div>
                        <b class="wq-muted">الأصول المسجلة على وحدتك</b>
                        <ul class="wq-list" style="margin-top:6px">
                            @forelse ($unit['assets'] as $a)
                                <li><span>{{ $a['name'] }}</span><span class="wq-muted">{{ $a['condition_at_handover'] ? 'حالته عند الاستلام ' . $a['condition_at_handover'] . ' من 5' : 'لم تُسجَّل حالته عند الاستلام' }}</span></li>
                            @empty
                                <li class="wq-muted">لا أصول مسجلة بعد.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </section>

            {{-- Invoices with payment --}}
            <section class="wq-panel" aria-labelledby="i-h">
                <header class="wq-panel-head"><h2 id="i-h">فواتيري</h2></header>
                @forelse ($invoices as $i)
                    <div class="wq-bill">
                        <div>
                            <strong><bdi>{{ $i['number'] }}</bdi></strong>
                            <div class="wq-muted">{{ $i['period'][0] }} — {{ $i['period'][1] }}</div>
                        </div>
                        <div style="text-align:end">
                            @if ($i['outstanding']->isPositive())
                                <div class="wq-bill-amt">{{ $sar($i['outstanding']) }}</div>
                                <div class="wq-muted">المتبقي من {{ $sar($i['total']) }}</div>
                            @else
                                <div class="wq-bill-amt">{{ $sar($i['total']) }}</div>
                                <div class="wq-muted">سُدّدت كاملة</div>
                            @endif
                        </div>
                        @if ($i['outstanding']->isPositive())
                            <x-filament::button icon="heroicon-m-credit-card" wire:click="pay({{ $i['id'] }})" style="width:100%">ادفع (مدى / Apple Pay / بطاقة)</x-filament::button>
                        @else
                            <span class="wq-chip wq-s-paid">مسددة</span>
                        @endif
                    </div>
                @empty
                    <div class="wq-empty">لا توجد فواتير.</div>
                @endforelse
            </section>
        </div>

        <div class="wq-grid">
            {{-- Maintenance report --}}
            <section class="wq-panel" aria-labelledby="r-h">
                <header class="wq-panel-head"><h2 id="r-h">بلاغ صيانة</h2></header>
                <form wire:submit="report" class="wq-body">
                    <label class="wq-field"><span>الأصل (اختياري)</span>
                        <select class="wq-input" wire:model="assetId"><option value="">— بدون تحديد —</option>@foreach ($unit['assets'] as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach</select></label>
                    <label class="wq-field"><span>وصف المشكلة</span>
                        <textarea class="wq-input" wire:model="description" placeholder="مثال: المكيف لا يبرد منذ أمس" required></textarea></label>
                    <label class="wq-file">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7h3l2-3h8l2 3h3v13H3z"/><circle cx="12" cy="13" r="4"/></svg>
                        <span wire:loading.remove wire:target="photos">{{ count($photos) ? count($photos) . ' ملف مرفق' : 'أرفق صوراً أو فيديو للمشكلة' }}</span>
                        <span wire:loading wire:target="photos">جارٍ الرفع…</span>
                        <input type="file" wire:model="photos" multiple accept="image/*,video/*" aria-label="صور أو فيديو">
                    </label>
                    <label class="wq-check"><input type="checkbox" wire:model="emergency"> طارئ (انقطاع كهرباء، تسريب غاز…)</label>
                    <x-filament::button type="submit" icon="heroicon-m-paper-airplane">إرسال البلاغ</x-filament::button>
                </form>
            </section>

            <section class="wq-panel" aria-labelledby="mr-h">
                <header class="wq-panel-head"><h2 id="mr-h">بلاغاتي</h2></header>
                <ul class="wq-list">
                    @forelse ($reports as $r)
                        <li>
                            <bdi>{{ $r['number'] }}</bdi>
                            <span class="wq-chip {{ $tone[$r['status']] ?? '' }}">{{ $statusAr[$r['status']] ?? $r['status'] }}</span>
                            @if ($r['status'] === 'completed')
                                <span style="flex-basis:100%" class="wq-muted">قيّم الخدمة:
                                    <span class="wq-stars">@for ($s = 1; $s <= 5; $s++)<button type="button" wire:click="rate({{ $r['id'] }}, {{ $s }})" aria-label="{{ $s }} من 5">★</button>@endfor</span>
                                </span>
                            @endif
                        </li>
                    @empty
                        <li class="wq-muted">لم ترسل أي بلاغ بعد.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    <div class="wq-grid">
        <section class="wq-panel" aria-labelledby="a-h">
            <header class="wq-panel-head"><h2 id="a-h">إعلانات الإدارة</h2></header>
            <ul class="wq-list">
                @forelse ($announcements as $a)
                    <li style="display:block"><strong>{{ $a['title_ar'] }}</strong><div class="wq-muted">{{ $a['body_ar'] }}</div></li>
                @empty
                    <li class="wq-muted">لا توجد إعلانات.</li>
                @endforelse
            </ul>
        </section>
        <section class="wq-panel" aria-labelledby="m-h">
            <header class="wq-panel-head"><h2 id="m-h">نقل العدادات</h2></header>
            <div class="wq-body"><p class="wq-note" style="margin:0">{{ $guidance['text_ar'] }}</p></div>
        </section>
    </div>
</x-filament-panels::page>
