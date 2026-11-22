<x-filament-panels::page>
    @if (! $unit)
        <x-filament::section>لا يوجد عقد ساري مرتبط بحسابك.</x-filament::section>
    @else
        <x-filament::section heading="وحدتي: {{ $unit['unit'] }}">
            <div>{{ \App\Properties\UnitTypes::get($unit['type'])['ar'] }}</div>
            <h4>الأصول المسجلة على وحدتك</h4>
            @foreach ($unit['assets'] as $a)
                <div>{{ $a['name'] }} — الحالة عند الاستلام: {{ $a['condition_at_handover'] ?? '—' }} / 5</div>
            @endforeach
        </x-filament::section>

        <x-filament::section heading="عقدي">
            <div>رقم عقد إيجار: {{ $lease['ejar_contract_number'] ?? '—' }}</div>
            <div>ينتهي في: {{ $lease['ends_on'] }}</div>
            <div>آخر موعد لإشعار عدم التجديد: {{ $lease['notice_deadline'] }}</div>
        </x-filament::section>

        <x-filament::section heading="فواتيري">
            @forelse ($invoices as $i)
                <div style="display:flex;gap:8px;align-items:center">
                    <span>{{ $i['number'] }} ({{ $i['period'][0] }} — {{ $i['period'][1] }}): المتبقي {{ $i['outstanding']->toDecimalString() }} من {{ $i['total']->toDecimalString() }} ر.س</span>
                    @if ($i['outstanding']->isPositive())<x-filament::button size="sm" wire:click="pay({{ $i['id'] }})">ادفع (مدى / Apple Pay / بطاقة)</x-filament::button>@endif
                </div>
            @empty
                <div>لا توجد فواتير.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="بلاغ صيانة">
            <form wire:submit="report" style="display:grid;gap:8px">
                <select wire:model="assetId"><option value="">الأصل (اختياري)</option>@foreach ($unit['assets'] as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach</select>
                <textarea wire:model="description" placeholder="صف المشكلة" required></textarea>
                <input type="file" wire:model="photos" multiple accept="image/*,video/*">
                <label><input type="checkbox" wire:model="emergency"> طارئ (انقطاع كهرباء، تسريب غاز…)</label>
                <x-filament::button type="submit">إرسال البلاغ</x-filament::button>
            </form>
            <h4>بلاغاتي</h4>
            @foreach ($reports as $r)
                <div>{{ $r['number'] }} — {{ \App\Filament\Manager\Resources\WorkOrders\WorkOrderResource::STATUS_AR[$r['status']] ?? $r['status'] }}
                    @if ($r['status'] === 'completed') — قيّم الخدمة: @for ($s = 1; $s <= 5; $s++)<button type="button" wire:click="rate({{ $r['id'] }}, {{ $s }})">{{ $s }}</button>@endfor @endif
                </div>
            @endforeach
        </x-filament::section>
    @endif

    <x-filament::section heading="إعلانات الإدارة">
        @forelse ($announcements as $a)<div><strong>{{ $a['title_ar'] }}</strong> — {{ $a['body_ar'] }}</div>@empty<div>لا توجد إعلانات.</div>@endforelse
    </x-filament::section>
    <x-filament::section heading="نقل العدادات">{{ $guidance['text_ar'] }}</x-filament::section>
</x-filament-panels::page>
