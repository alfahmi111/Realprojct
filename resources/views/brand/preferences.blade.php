@php
    $u = auth()->user();
    $s = app(\App\Settings\Settings::class);
    $p = $u ? [
        'font' => $s->get('a11y.font_size', $u->id) ?? 'normal',
        'contrast' => (bool) $s->get('a11y.high_contrast', $u->id),
        'motion' => (bool) $s->get('a11y.reduce_motion', $u->id),
        'density' => $s->get('a11y.table_density', $u->id) ?? 'comfortable',
        'mode' => $s->get('appearance.mode', $u->id) ?? 'auto',
    ] : null;
@endphp
{{-- Per-user accessibility and appearance (REQ-0373, REQ-0338), applied to the whole interface. --}}
<style>
    html[data-font-size="large"] { font-size: 112.5%; }
    html[data-font-size="larger"] { font-size: 125%; }
    html[data-contrast="high"] { --color-muted: var(--color-ink); }
    html[data-contrast="high"] body { filter: contrast(1.15); }
    html[data-contrast="high"] a, html[data-contrast="high"] .fi-link { text-decoration: underline; }
    html[data-reduce-motion="1"] *, html[data-reduce-motion="1"] *::before, html[data-reduce-motion="1"] *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
    html[data-density="compact"] .fi-ta-cell, html[data-density="compact"] .fi-ta-header-cell { padding-top: .25rem !important; padding-bottom: .25rem !important; }
</style>
@if ($p)
<script>
    (function () {
        var h = document.documentElement;
        h.dataset.fontSize = @json($p['font']);
        h.dataset.contrast = @json($p['contrast'] ? 'high' : 'normal');
        h.dataset.reduceMotion = @json($p['motion'] ? '1' : '0');
        h.dataset.density = @json($p['density']);
        h.dataset.themeMode = @json($p['mode']);
        try { localStorage.setItem('theme', @json(['auto' => 'system', 'light' => 'light', 'dark' => 'dark'][$p['mode']])); } catch (e) {}
    })();
</script>
@endif
