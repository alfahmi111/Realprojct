{{-- وِثاق wordmark: the mark (currentColor = theme primary) + the Arabic name. --}}
<span style="display:inline-flex;align-items:center;gap:.5rem;color:var(--color-primary, currentColor)">
    <span style="display:inline-block;width:2rem;height:2rem">{!! file_get_contents(public_path('brand/withaq-mark.svg')) !!}</span>
    <span style="font-family:var(--font-heading, inherit);font-weight:700;font-size:1.35rem;letter-spacing:0;color:var(--color-ink, inherit)">وِثاق</span>
</span>
