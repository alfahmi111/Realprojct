@php($render = function ($nodes, $depth = 0) use (&$render) {
    foreach ($nodes as $n) {
        echo '<div style="padding-inline-start:' . ($depth * 18) . 'px">' . e(($n['code'] ?? '') . ' — ' . ($n['name'] ?? '')) . ($n['type'] ? ' <small>(' . e(\App\Properties\UnitTypes::get($n['type'])['ar']) . ')</small>' : '') . '</div>';
        $render($n['children'] ?? [], $depth + 1);
    }
})
<div>
    @foreach ($getState() ?? [] as $b)
        <div style="margin-bottom:8px"><strong>{{ $b['building'] ?? 'أرض' }}</strong>
            @php($render($b['children']))
        </div>
    @endforeach
</div>
