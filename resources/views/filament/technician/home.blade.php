@php
    $statusAr = \App\Filament\Manager\Resources\WorkOrders\WorkOrderResource::STATUS_AR;
    $priorityAr = ['emergency' => 'طارئ', 'urgent' => 'عاجل', 'normal' => 'عادي', 'scheduled' => 'مجدول'];
    $camera = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7h3l2-3h8l2 3h3v13H3z"/><circle cx="12" cy="13" r="4"/></svg>';
@endphp
<x-filament-panels::page class="wq-portal">
    <section class="wq-hello" aria-label="ترحيب">
        <div class="wq-hello-who">
            <span class="wq-hello-avatar" aria-hidden="true">{{ mb_substr($header['name'], 0, 1) }}</span>
            <div>
                <h1 class="wq-hello-title">{{ $header['greeting'] }}، {{ $header['name'] }}</h1>
                <p class="wq-hello-sub">
                    {{ count($orders) ? (count($orders) === 1 ? 'مهمة واحدة مسندة إليك' : count($orders) . ' مهام مسندة إليك') : 'لا مهام مسندة إليك الآن' }}
                    <span class="wq-hello-role">{{ $header['role'] }}</span>
                </p>
            </div>
        </div>
        <div class="wq-hello-date">
            <span class="wq-hello-day">{{ $header['day'] }}</span>
            <strong class="wq-hello-hijri">{{ $header['hijri'] }}</strong>
        </div>
    </section>

    <div class="wq-grid">
        {{-- Assigned work orders: priority on the edge, one step at a time --}}
        <section class="wq-panel" aria-labelledby="t-h">
            <header class="wq-panel-head"><h2 id="t-h">أوامر العمل المسندة إليّ</h2></header>
            @forelse ($orders as $o)
                <article class="wq-task-card wq-p-{{ $o['priority'] }}">
                    <div class="wq-task-head">
                        <span class="wq-chip">{{ $priorityAr[$o['priority']] ?? $o['priority'] }}</span>
                        <span class="wq-muted"><bdi><strong>{{ $o['number'] }}</strong></bdi> · {{ $statusAr[$o['status']] ?? $o['status'] }}</span>
                    </div>
                    <p class="wq-task-desc">{{ $o['description'] }}</p>

                    @if ($o['status'] === 'assigned')
                        <div class="wq-step">
                            <b>قبل البدء: صوّر الوضع الحالي</b>
                            <label class="wq-file">{!! $camera !!}
                                <span>{{ count($before) ? count($before) . ' صورة جاهزة' : 'التقط صور «قبل»' }}</span>
                                <input type="file" wire:model="before" multiple accept="image/*" capture="environment" aria-label="صور قبل">
                            </label>
                            <x-filament::button icon="heroicon-m-play" wire:click="start({{ $o['id'] }})">بدء (صور قبل)</x-filament::button>
                        </div>
                    @endif

                    @if (in_array($o['status'], ['assigned', 'in_progress'], true))
                        <div class="wq-step">
                            <b>تكلفة قطع أو وقت</b>
                            <div class="wq-row">
                                <label class="wq-field" style="flex:0 1 130px"><span>النوع</span>
                                    <select class="wq-input" wire:model="cost.kind"><option value="part">قطع</option><option value="labour">وقت/عمالة</option></select></label>
                                <label class="wq-field"><span>البند</span><input class="wq-input" wire:model="cost.description" placeholder="مثال: مكثف 35 ميكرو"></label>
                            </div>
                            <div class="wq-row">
                                <label class="wq-field" style="flex:0 1 90px"><span>الكمية</span><input class="wq-input" wire:model="cost.quantity" type="number" min="1" inputmode="numeric"></label>
                                <label class="wq-field"><span>سعر الوحدة ({{ \App\Support\Dates\NumberFormatter::currencyLabel() }})</span><input class="wq-input" wire:model="cost.unit_cost" placeholder="0.00" inputmode="decimal"></label>
                                <x-filament::button color="gray" icon="heroicon-m-plus" wire:click="addCost({{ $o['id'] }})">إضافة</x-filament::button>
                            </div>
                        </div>
                    @endif

                    @if ($o['status'] === 'in_progress')
                        <div class="wq-step">
                            <b>بعد الانتهاء: صوّر النتيجة</b>
                            <label class="wq-file">{!! $camera !!}
                                <span>{{ count($after) ? count($after) . ' صورة جاهزة' : 'التقط صور «بعد»' }}</span>
                                <input type="file" wire:model="after" multiple accept="image/*" capture="environment" aria-label="صور بعد">
                            </label>
                            <x-filament::button color="success" icon="heroicon-m-check" wire:click="complete({{ $o['id'] }})">إنجاز (صور بعد)</x-filament::button>
                        </div>
                    @endif
                </article>
            @empty
                <div class="wq-empty">لا توجد مهام. ستصلك إشعارات عند إسناد أمر عمل جديد إليك.</div>
            @endforelse
        </section>

        <div style="display:grid;gap:20px">
            {{-- Asset scan --}}
            <section class="wq-panel" aria-labelledby="q-h">
                <header class="wq-panel-head"><h2 id="q-h">مسح رمز أصل (QR)</h2></header>
                <form wire:submit="doScan" class="wq-body">
                    <div class="wq-row">
                        <label class="wq-field"><span>الرمز</span><input class="wq-input" wire:model="scan" placeholder="رمز QR" inputmode="text" autocomplete="off"></label>
                        <x-filament::button type="submit" icon="heroicon-m-qr-code">فتح السجل</x-filament::button>
                    </div>
                    @if ($scanned)
                        <div class="wq-facts">
                            <div class="wq-fact"><span>الأصل</span><strong>{{ $scanned['asset']['name_ar'] }}</strong></div>
                            <div class="wq-fact"><span>الطراز</span><strong>{{ trim($scanned['asset']['brand'] . ' ' . $scanned['asset']['model']) ?: '—' }}</strong></div>
                            <div class="wq-fact"><span>الحالة</span><strong>{{ $scanned['asset']['condition'] }}/5</strong></div>
                            <div class="wq-fact"><span>تكلفة الإصلاحات التراكمية</span><strong>{{ $scanned['cumulative'] }}</strong></div>
                        </div>
                    @endif
                </form>
            </section>

            {{-- Founding inventory --}}
            <section class="wq-panel" aria-labelledby="c-h">
                <header class="wq-panel-head"><h2 id="c-h">جرد التأسيس</h2></header>
                <div class="wq-body">
                    @if (! $count)
                        <div class="wq-row">
                            <label class="wq-field"><span>الوحدة</span>
                                <select class="wq-input" wire:model="countSpace"><option value="">اختر الوحدة</option>@foreach ($units as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach</select></label>
                            <x-filament::button icon="heroicon-m-clipboard-document-check" wire:click="startCount">بدء الجرد</x-filament::button>
                        </div>
                    @else
                        <div>
                            <b class="wq-muted">الأصول المتوقعة</b>
                            <div class="wq-checklist" style="margin-top:6px">
                                @foreach ($expected as $a)
                                    <span class="wq-chip {{ in_array($a->id, $recorded, true) ? 'wq-s-paid' : '' }}">{{ in_array($a->id, $recorded, true) ? '✓' : '○' }} {{ $a->name_ar }} <bdi>({{ $a->qr_code }})</bdi></span>
                                @endforeach
                            </div>
                        </div>
                        <div class="wq-step">
                            <div class="wq-row">
                                <label class="wq-field"><span>رمز QR للأصل</span><input class="wq-input" wire:model="line.qr" placeholder="رمز QR للأصل"></label>
                                <label class="wq-field" style="flex:0 1 110px"><span>الحالة 1–5</span>
                                    <select class="wq-input" wire:model="line.condition">@for ($c = 1; $c <= 5; $c++)<option value="{{ $c }}">{{ $c }}</option>@endfor</select></label>
                            </div>
                            <label class="wq-check"><input type="checkbox" wire:model="line.present"> موجود</label>
                            <label class="wq-file">{!! $camera !!}
                                <span>{{ $linePhoto ? 'الصورة جاهزة' : 'صورة الأصل' }}</span>
                                <input type="file" wire:model="linePhoto" accept="image/*" capture="environment" aria-label="صورة الأصل">
                            </label>
                            <label class="wq-field"><span>ملاحظة</span><input class="wq-input" wire:model="line.note" placeholder="ملاحظة"></label>
                            <x-filament::button color="gray" icon="heroicon-m-plus" wire:click="recordLine({{ $count->id }})">تسجيل</x-filament::button>
                        </div>
                        <x-filament::button color="success" icon="heroicon-m-pencil-square" wire:click="submitCount({{ $count->id }})">توقيع وإرسال الجرد</x-filament::button>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
