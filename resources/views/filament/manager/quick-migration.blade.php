@php
    $step = $batchId === null ? 1 : 2;
    $recent = $this->recent();
    $base = filament()->getCurrentPanel()->getUrl();
    $statusAr = ['staged' => ['بانتظار مراجعتك', 'wq-s-vacant'], 'confirmed' => ['حُفظ', 'wq-st-active'], 'discarded' => ['تُجوهل', 'wq-st-cancelled']];
@endphp
<x-filament-panels::page class="wq-page wq-migrate">
    <header class="wq-idband">
        <div class="wq-idband-text">
            <h1>الانتقال السريع</h1>
            <p>انقل عقودك القائمة دون إدخال يدوي: ارفع عقد إيجار أو صورة عقد أو جدولاً، فيقرأ النظام الحقول، وتراجعها أنت. لا يُحفظ شيء قبل تأكيدك.</p>
        </div>
    </header>

    {{-- The three steps: a real sequence, so numbered --}}
    <ol class="wq-steps" aria-label="الخطوات">
        <li @class(['is-on' => $step === 1, 'is-done' => $step > 1])><span>1</span><div><strong>ارفع الملف</strong><small>PDF من إيجار، أو صورة، أو Excel</small></div></li>
        <li @class(['is-on' => $step === 2])><span>2</span><div><strong>راجع الحقول</strong><small>بجانب كل حقل درجة الثقة في قراءته</small></div></li>
        <li><span>3</span><div><strong>أكّد</strong><small>يُنشأ المستأجر والعقد وجدول الدفعات</small></div></li>
    </ol>

    @if ($step === 1)
        <aside class="wq-help wq-template">
            <div><strong>تنقل عدة عقود دفعة واحدة؟</strong> نزّل قالب Excel، واملأ صفاً لكل عقد، ثم ارفعه هنا باختيار «جدول Excel». يقرؤه النظام بنفسه وتراجع كل عقد قبل حفظه.</div>
            <a class="wq-btn" href="{{ route('migration.template') }}">نزّل القالب (xlsx)</a>
        </aside>
    @endif
    <section class="wq-panel wq-migrate-body">
        {{ $this->content }}
    </section>

    @if (count($recent))
        <section class="wq-panel" aria-labelledby="rec-h">
            <header class="wq-panel-head"><h2 id="rec-h">آخر ما نُقل</h2></header>
            <ul class="wq-nearlist">
                @foreach ($recent as $r)
                    <li class="wq-rec">
                        <span class="wq-near-what"><strong>{{ $r['tenant'] ?? $r['kind'] }}</strong><small>{{ $r['kind'] }}، {{ $r['at'] }}</small></span>
                        <span class="wq-chip {{ $statusAr[$r['status']][1] ?? '' }}">{{ $statusAr[$r['status']][0] ?? $r['status'] }}</span>
                        <span class="wq-near-cost">@if ($r['lease_id'])<a class="wq-link" href="{{ $base }}/leases/{{ $r['lease_id'] }}">افتح العقد</a>@elseif ($r['status'] === 'staged')<button type="button" class="wq-link" wire:click="openBatch({{ $r['id'] }})">راجعه الآن</button>@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-filament-panels::page>
