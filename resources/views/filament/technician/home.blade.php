<x-filament-panels::page>
    <x-filament::section heading="مسح رمز أصل (QR)">
        <form wire:submit="doScan" style="display:flex;gap:8px"><input wire:model="scan" placeholder="رمز QR" inputmode="text"><x-filament::button type="submit">فتح السجل</x-filament::button></form>
        @if ($scanned)
            <div>{{ $scanned['asset']['name_ar'] }} — {{ $scanned['asset']['brand'] }} {{ $scanned['asset']['model'] }} — الحالة {{ $scanned['asset']['condition'] }}/5 — تكلفة الإصلاحات التراكمية {{ $scanned['cumulative'] }} ر.س</div>
        @endif
    </x-filament::section>

    <x-filament::section heading="أوامر العمل المسندة إليّ">
        @forelse ($orders as $o)
            <div style="border-bottom:1px solid #ddd;padding:8px 0">
                <strong>{{ $o['number'] }}</strong> — {{ $o['priority'] }} — {{ \App\Filament\Manager\Resources\WorkOrders\WorkOrderResource::STATUS_AR[$o['status']] ?? $o['status'] }}<div>{{ $o['description'] }}</div>
                @if ($o['status'] === 'assigned')
                    <input type="file" wire:model="before" multiple accept="image/*"> <x-filament::button size="sm" wire:click="start({{ $o['id'] }})">بدء (صور قبل)</x-filament::button>
                @endif
                @if (in_array($o['status'], ['assigned', 'in_progress'], true))
                    <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">
                        <select wire:model="cost.kind"><option value="part">قطع</option><option value="labour">وقت/عمالة</option></select>
                        <input wire:model="cost.description" placeholder="البند"><input wire:model="cost.quantity" type="number" min="1" style="width:60px"><input wire:model="cost.unit_cost" placeholder="السعر" inputmode="decimal" style="width:90px">
                        <x-filament::button size="sm" color="gray" wire:click="addCost({{ $o['id'] }})">إضافة</x-filament::button>
                    </div>
                @endif
                @if ($o['status'] === 'in_progress')
                    <input type="file" wire:model="after" multiple accept="image/*"> <x-filament::button size="sm" color="success" wire:click="complete({{ $o['id'] }})">إنجاز (صور بعد)</x-filament::button>
                @endif
            </div>
        @empty
            <div>لا توجد مهام.</div>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="جرد التأسيس">
        @if (! $count)
            <div style="display:flex;gap:8px"><select wire:model="countSpace"><option value="">اختر الوحدة</option>@foreach ($units as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach</select>
                <x-filament::button wire:click="startCount">بدء الجرد</x-filament::button></div>
        @else
            <div>الأصول المتوقعة: @foreach ($expected as $a)<span style="margin-inline-end:8px">{{ in_array($a->id, $recorded, true) ? '✓' : '○' }} {{ $a->name_ar }} ({{ $a->qr_code }})</span>@endforeach</div>
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
                <input wire:model="line.qr" placeholder="رمز QR للأصل">
                <label><input type="checkbox" wire:model="line.present"> موجود</label>
                <select wire:model="line.condition">@for ($c = 1; $c <= 5; $c++)<option value="{{ $c }}">{{ $c }}</option>@endfor</select>
                <input type="file" wire:model="linePhoto" accept="image/*" capture="environment">
                <input wire:model="line.note" placeholder="ملاحظة">
                <x-filament::button size="sm" wire:click="recordLine({{ $count->id }})">تسجيل</x-filament::button>
            </div>
            <x-filament::button color="success" wire:click="submitCount({{ $count->id }})" style="margin-top:8px">توقيع وإرسال الجرد</x-filament::button>
        @endif
    </x-filament::section>
</x-filament-panels::page>
