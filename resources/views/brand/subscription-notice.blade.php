@php
    // Shown to office staff only (manager panel roles); tenants and owners are not told about the office's subscription.
    $user = auth()->user();
    $notice = null;
    if ($user && $user->subscriber_id && in_array($user->role?->portal(), ['manager'], true)) {
        $s = app(\App\Tenancy\TenantContext::class)->run(null, fn () => \App\Models\Subscriber::find($user->subscriber_id));
        if ($s) {
            $admin = app(\App\Plans\SubscriberAdmin::class);
            $today = \Carbon\CarbonImmutable::today();
            $state = $admin->state($s, $today);
            $days = $admin->daysRemaining($s, $today);
            $notice = match ($state) {
                'expiring' => ['tone' => 'soon', 'text' => $days === 0 ? 'ينتهي اشتراك المكتب اليوم.' : "ينتهي اشتراك المكتب خلال $days يوماً ({$s->subscription_ends_on->format('Y-m-d')}). تواصل مع إدارة المنصة للتجديد."],
                'grace' => ['tone' => 'late', 'text' => 'انتهى اشتراك المكتب في ' . $s->subscription_ends_on->format('Y-m-d') . '، والمنصة تعمل كاملة خلال مهلة السماح. ' . (($left = $admin->graceLeft($s, $today)) === 0 ? 'اليوم آخر أيام المهلة' : "متبقٍ $left يوماً") . '، ثم تصبح للقراءة فقط حتى التجديد.'],
                'read_only' => ['tone' => 'late', 'text' => 'انتهى اشتراك المكتب ومهلة السماح، فالمنصة في وضع القراءة فقط: الاطلاع والتصدير متاحان، ولا يُحفظ أي تعديل حتى يُجدَّد الاشتراك.'],
                default => null,
            };
        }
    }
@endphp
@if ($notice)
    <div class="wq-subnotice wq-subnotice-{{ $notice['tone'] }}" role="status">{{ $notice['text'] }}</div>
@endif
