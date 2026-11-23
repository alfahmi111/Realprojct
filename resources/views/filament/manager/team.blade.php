<x-filament-panels::page>
    <section class="wq-panel" aria-label="الهيكل التنظيمي">
        <header class="wq-panel-head"><h2>فريق المكتب</h2><span class="wq-panel-meta">{{ $team['count'] }} موظفين حسب الإدارة ومن يتبع لمن</span></header>
        @include('filament.partials.team-chart', ['tree' => $team, 'mode' => 'office'])
    </section>
</x-filament-panels::page>
