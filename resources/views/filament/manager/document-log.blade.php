<div class="wq-doclog">
    <h3>النسخ</h3>
    <ul>
        <li><strong>النسخة {{ $doc->version }} (الحالية)</strong> — رفعها {{ $doc->uploader?->name ?? '—' }} في {{ $doc->created_at?->format('Y-m-d H:i') }}@if ($doc->expires_on)، تنتهي {{ $doc->expires_on->format('Y-m-d') }}@endif</li>
        @foreach ($versions as $v)
            <li>النسخة {{ $v->version }} — رفعها {{ $v->uploader?->name ?? '—' }} في {{ $v->created_at?->format('Y-m-d H:i') }}، <a href="{{ route('media.document', $v->id) }}" target="_blank">فتح</a></li>
        @endforeach
    </ul>
    <h3>من اطّلع عليه</h3>
    <ul>
        @forelse ($views as $v)
            <li>{{ $v->user?->name }} — {{ $v->action === 'download' ? 'تنزيل' : 'عرض' }} في {{ $v->viewed_at?->format('Y-m-d H:i') }}</li>
        @empty
            <li>لم يطّلع عليه أحد بعد.</li>
        @endforelse
    </ul>
</div>
