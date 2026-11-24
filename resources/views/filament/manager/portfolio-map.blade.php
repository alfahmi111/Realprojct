@php
    $points = collect($cards)->filter(fn ($c) => $c['lat'] !== null && $c['lng'] !== null)->map(fn ($c) => [
        'lat' => $c['lat'], 'lng' => $c['lng'], 'name' => $c['name'], 'city' => $c['city'],
        'occ' => intdiv($c['counts']['occupancy_bp'], 100), 'units' => $c['counts']['total'], 'vacant' => $c['counts']['vacant'],
        'url' => $base . '/properties/' . $c['id'],
    ])->values();
    $missing = count($cards) - $points->count();
@endphp
{{-- Portfolio map (owner request 2026-11-24): every managed property where it is, occupancy in the pin's colour. --}}
<section class="wq-panel" aria-labelledby="pmap-h">
    <header class="wq-panel-head">
        <h2 id="pmap-h">خريطة العقارات</h2>
        <span class="wq-panel-meta">{{ $points->count() }} على الخريطة@if ($missing)، و{{ $missing }} بلا موقع محدد (حدّده من «تعديل»)@endif</span>
    </header>
    @if ($points->count())
        <div
            wire:ignore
            x-data="{
                pts: @js($points),
                init() {
                    const go = () => {
                        const map = L.map(this.$refs.m, { scrollWheelZoom: false });
                        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
                        const b = [];
                        this.pts.forEach(p => {
                            const c = p.occ >= 90 ? '#2E6B3A' : (p.occ >= 60 ? '#B07A12' : '#9B2C2C');
                            const m = L.circleMarker([p.lat, p.lng], { radius: 10, color: '#fff', weight: 2, fillColor: c, fillOpacity: .95 }).addTo(map);
                            const d = document.createElement('div'); d.className = 'wq-pmap-pop';
                            const s = document.createElement('strong'); s.textContent = p.name; d.appendChild(s);
                            const t = document.createElement('div'); t.textContent = (p.city || '') + ' — إشغال ' + p.occ + '%، ' + p.vacant + ' شاغرة من ' + p.units; d.appendChild(t);
                            const a = document.createElement('a'); a.href = p.url; a.textContent = 'افتح العقار'; d.appendChild(a);
                            m.bindPopup(d);
                            b.push([p.lat, p.lng]);
                        });
                        b.length === 1 ? map.setView(b[0], 15) : map.fitBounds(b, { padding: [40, 40] });
                    };
                    if (window.L) return go();
                    const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css'; document.head.appendChild(css);
                    const js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js'; js.onload = go; document.head.appendChild(js);
                },
            }"
        ><div x-ref="m" class="wq-pmap" role="application" aria-label="خريطة مواقع العقارات المدارة"></div></div>
        <ul class="wq-legend"><li><span class="wq-dot" style="--fg:#2E6B3A;background:#2E6B3A"></span>إشغال 90% فأكثر</li><li><span class="wq-dot" style="background:#B07A12"></span>من 60% إلى 89%</li><li><span class="wq-dot" style="background:#9B2C2C"></span>أقل من 60%</li></ul>
    @else
        <p class="wq-calm">لم يُحدَّد موقع أي عقار بعد. افتح «تعديل» في بطاقة العقار وحدّد موقعه على الخريطة، فيظهر هنا.</p>
    @endif
</section>
