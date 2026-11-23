@php
    $d = $this->data();
    $w = $d['w'];
    $R = \App\Filament\Manager\Resources\WorkOrders\WorkOrderResource::class;
    $money = fn ($m) => \App\Support\Dates\NumberFormatter::money($m);
    $base = filament()->getCurrentPanel()->getUrl();
    $kind = ['labour' => 'عمالة', 'part' => 'قطع', 'vendor' => 'مورّد'];
    $est = $w->estimated_cost_minor?->minor();
@endphp
<x-filament-panels::page class="wq-page">
    <header class="wq-contract-head">
        <div>
            <a class="wq-back" href="{{ $base }}/work-orders">أوامر العمل</a>
            <h1>{{ $w->description }}</h1>
            <p>{{ $w->number }}، {{ $w->property?->name_ar }}@if ($w->space)، {{ $w->space->name_ar }}@endif</p>
        </div>
        <div class="wq-contract-stamp">
            <span class="wq-chip {{ $w->status === 'reported' ? 'wq-s-late' : (in_array($w->status, ['completed', 'confirmed', 'closed'], true) ? 'wq-st-active' : 'wq-st-draft') }}">{{ $R::STATUS_AR[$w->status] ?? $w->status }}</span>
            <strong>{{ $R::TYPE_AR[$w->type] ?? $w->type }}@if ($w->priority)، {{ $R::PRIORITY_AR[$w->priority] }}@endif</strong>
            <small>{{ $w->reported_at?->format('Y-m-d H:i') }}</small>
        </div>
    </header>

    {{-- Stages from report to close: a real sequence --}}
    <ol class="wq-flow" aria-label="مراحل أمر العمل">
        @foreach ($d['flow'] as $i => $s)
            <li class="is-{{ $s['state'] }}"><span>{{ $i + 1 }}</span><strong>{{ $s['label'] }}</strong>@if ($s['at'])<small>{{ $s['at']->format('m-d H:i') }}</small>@endif</li>
        @endforeach
    </ol>
    @if ($w->status === 'awaiting_owner_approval')
        <p class="wq-help">التكلفة التقديرية {{ $money($est) }} تتجاوز سقف الصرف دون موافقة المالك، فأمر العمل ينتظر موافقته قبل الإسناد.</p>
    @elseif ($w->status === 'rejected')
        <p class="wq-help">رُفض أمر العمل.</p>
    @endif

    <div class="wq-sheet-two">
        <section class="wq-sheet">
            <h2><span>أ</span>البلاغ</h2>
            <dl class="wq-fields wq-fields-2">
                <div><dt>العقار</dt><dd>{{ $w->property?->name_ar }}</dd></div>
                <div><dt>الموقع</dt><dd>{{ $w->space?->name_ar ?? 'المبنى عموماً' }}</dd></div>
                <div><dt>الأصل</dt><dd>{{ $w->asset?->name_ar ?? '—' }}</dd></div>
                <div><dt>أبلغ عنه</dt><dd>{{ ['tenant' => 'المستأجر', 'supervisor' => 'المشرف', 'system' => 'النظام (صيانة وقائية)'][$w->reported_by_type] ?? $w->reported_by_type }}</dd></div>
                <div class="wq-wide"><dt>الوصف</dt><dd>{{ $w->description }}</dd></div>
            </dl>
        </section>
        <section class="wq-sheet">
            <h2><span>ب</span>التنفيذ</h2>
            <dl class="wq-fields wq-fields-2">
                <div><dt>المنفّذ</dt><dd>{{ $w->vendor?->name_ar ?? $w->assignee?->name ?? 'لم يُسند بعد' }}</dd></div>
                <div><dt>جهة التنفيذ</dt><dd>{{ ['internal' => 'فني داخلي', 'vendor' => 'مورّد'][$w->assignee_type] ?? '—' }}</dd></div>
                <div><dt>موافقة المالك</dt><dd>{{ $w->owner_approval_required ? (['pending' => 'بانتظارها', 'approved' => 'وافق', 'rejected' => 'رفض'][$w->owner_approval] ?? 'مطلوبة') : 'غير مطلوبة' }}</dd></div>
                <div><dt>تقييم المستأجر</dt><dd>{{ $w->tenant_rating ? str_repeat('★', $w->tenant_rating) . str_repeat('☆', 5 - $w->tenant_rating) : '—' }}</dd>@if ($w->tenant_feedback)<small>{{ $w->tenant_feedback }}</small>@endif</div>
            </dl>
        </section>
    </div>

    <section class="wq-sheet">
        <h2><span>ج</span>التكلفة</h2>
        <dl class="wq-fields">
            <div><dt>التقديرية</dt><dd>{{ $est ? $money($est) : '—' }}</dd></div>
            <div><dt>الفعلية</dt><dd class="wq-strong">{{ $d['actual'] ? $money($d['actual']) : '—' }}</dd></div>
            <div><dt>الفرق</dt><dd>@if ($est && $d['actual']){{ $d['actual'] > $est ? 'أعلى بـ ' : 'أقل بـ ' }}{{ $money(abs($d['actual'] - $est)) }}@else — @endif</dd></div>
        </dl>
        @if ($w->costLines->isNotEmpty())
            <div class="wq-table-wrap">
                <table class="wq-table">
                    <thead><tr><th>البند</th><th>النوع</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
                    <tbody>
                        @foreach ($w->costLines as $c)
                            <tr><td>{{ $c->description_ar }}</td><td>{{ $kind[$c->kind] ?? $c->kind }}</td><td>{{ $c->quantity }}</td><td>{{ $money($c->unit_cost_minor) }}</td><td>{{ $money($c->total_minor) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="wq-note">الإجراءات (الفرز، التقدير، الإسناد، إضافة التكلفة، الإغلاق) من <a href="{{ $base }}/work-orders">أوامر العمل</a>.</p>
    </section>
</x-filament-panels::page>
