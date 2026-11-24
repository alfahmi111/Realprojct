{{-- وِثاق logo in the panel header: the square-Kufic wordmark «الحضن الكوفي» (owner's choice 2026-11-25). The word is
     the logo, so there is no separate name text. currentColor = the theme primary (the interface stays teal, option 1). --}}
<span class="wq-logo" style="display:inline-flex;align-items:center;height:100%;color:var(--color-primary, currentColor)">
    <span class="wq-logo-word" style="display:inline-block;height:100%;aspect-ratio:14/11">{!! file_get_contents(public_path('brand/withaq-wordmark.svg')) !!}</span>
</span>
