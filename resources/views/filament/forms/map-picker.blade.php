@php
    $base = \Illuminate\Support\Str::beforeLast($getStatePath(), '.');
    $latPath = $base . '.latitude';
    $lngPath = $base . '.longitude';
@endphp
{{-- Location on a map (owner request 2026-11-24, spec 4.5 step 1 «الموقع على الخريطة»): tap or drag the pin. --}}
<div
    x-data="{
        lat: $wire.entangle('{{ $latPath }}'),
        lng: $wire.entangle('{{ $lngPath }}'),
        map: null, pin: null, msg: '',
        load(cb) {
            if (window.L) return cb();
            const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css'; document.head.appendChild(css);
            const js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js'; js.onload = cb; document.head.appendChild(js);
        },
        place(la, ln, zoom) {
            la = Number(la).toFixed(7); ln = Number(ln).toFixed(7);
            this.lat = la; this.lng = ln;
            if (this.pin) { this.pin.setLatLng([la, ln]); } else {
                this.pin = L.marker([la, ln], { draggable: true }).addTo(this.map);
                this.pin.on('dragend', e => { const p = e.target.getLatLng(); this.place(p.lat, p.lng); });
            }
            if (zoom) this.map.setView([la, ln], zoom);
        },
        init() {
            this.load(() => {
                const has = this.lat && this.lng;
                this.map = L.map(this.$refs.map, { attributionControl: true }).setView(has ? [this.lat, this.lng] : [24.7136, 46.6753], has ? 17 : 5);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(this.map);
                if (has) this.place(this.lat, this.lng);
                this.map.on('click', e => this.place(e.latlng.lat, e.latlng.lng));
                setTimeout(() => this.map.invalidateSize(), 300);
            });
        },
        locate() {
            if (! navigator.geolocation) { this.msg = 'المتصفح لا يتيح تحديد الموقع.'; return; }
            this.msg = 'جارٍ تحديد موقعك…';
            navigator.geolocation.getCurrentPosition(p => { this.msg = ''; this.place(p.coords.latitude, p.coords.longitude, 18); }, () => { this.msg = 'تعذّر تحديد موقعك؛ حدّده على الخريطة.'; });
        },
    }"
    class="wq-mappick"
>
    <div class="wq-mappick-bar">
        <span x-text="lat && lng ? ('الموقع: ' + lat + '، ' + lng) : 'اضغط على الخريطة لتحديد موقع المبنى، أو اسحب الدبوس.'"></span>
        <button type="button" class="wq-btn-quiet" x-on:click="locate()">موقعي الحالي</button>
    </div>
    <div x-ref="map" wire:ignore class="wq-mappick-map" role="application" aria-label="خريطة لتحديد موقع المبنى"></div>
    <small class="wq-mappick-msg" x-show="msg" x-text="msg"></small>
</div>
