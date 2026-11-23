<x-filament-panels::page>
    <x-filament::section heading="اعتمادات معلّقة ({{ $inbox['counts']['approvals'] }})">
        @forelse ($inbox['approvals']['offers'] as $o)<div>عرض تأجير #{{ $o['offer_id'] }} — الخطوة {{ $o['step'] }}</div>@empty<div>لا يوجد</div>@endforelse
        @foreach ($inbox['approvals']['entries'] as $e)<div>قيد حساس: {{ \App\Filament\Manager\Resources\ApprovalRequests\ApprovalRequestResource::ACTIONS_AR[$e['action']] ?? $e['action'] }} #{{ $e['id'] }}</div>@endforeach
    </x-filament::section>
    <x-filament::section heading="بلاغات جديدة ({{ $inbox['counts']['reports'] }})">
        @forelse ($inbox['new_reports'] as $r)<div>{{ $r['number'] }} — {{ $r['type'] }} — {{ $r['priority'] ?? 'بلا أولوية' }}</div>@empty<div>لا يوجد</div>@endforelse
    </x-filament::section>
    <x-filament::section heading="متأخرات مصعّدة ({{ $inbox['counts']['arrears'] }})">
        @forelse ($inbox['escalated_arrears'] as $a)<div>{{ $a['body'] }}</div>@empty<div>لا يوجد</div>@endforelse
    </x-filament::section>
</x-filament-panels::page>
