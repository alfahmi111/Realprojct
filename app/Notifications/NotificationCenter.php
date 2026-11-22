<?php

namespace App\Notifications;

use App\Integrations\IntegrationNotConfigured;
use App\Integrations\Sms\SmsGateway;
use App\Integrations\WhatsApp\WhatsAppGateway;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Event × channel × recipient (REQ-0353). The subscriber decides per event: enabled, channels, recipients (REQ-0355);
 * mandatory events cannot be switched off by users (REQ-0356); WhatsApp/SMS to tenants are held during quiet hours
 * except emergencies (REQ-0357); managers/owners may get one morning digest (REQ-0358); unacknowledged items escalate
 * (REQ-0359); templates with variables in the recipient's language, WhatsApp only with Meta-approved templates
 * (REQ-0360); WhatsApp/SMS usage is counted (REQ-0361). WhatsApp is the main channel, push complementary (REQ-0496).
 */
class NotificationCenter
{
    public const CHANNELS = ['in_app', 'push', 'whatsapp', 'sms', 'email'];

    public const EVENTS = [
        'payment.due' => 'دفعة مستحقة', 'payment.overdue' => 'دفعة متأخرة', 'payment.received' => 'دفعة مستلمة',
        'lease.expiring' => 'عقد قارب الانتهاء', 'renewal.survey_reply' => 'رد على استطلاع التجديد',
        'maintenance.new_report' => 'بلاغ صيانة جديد', 'maintenance.emergency' => 'بلاغ طارئ', 'work_order.late' => 'أمر عمل تأخر عن موعده',
        'approval.waiting' => 'اعتماد ينتظرك', 'document.expiring' => 'مستند قارب الانتهاء', 'waitlist.new_prospect' => 'مهتم جديد في قائمة الانتظار',
        'inventory.due' => 'جرد مستحق', 'security.new_device' => 'دخول من جهاز جديد',
    ];

    public function __construct(private Settings $settings, private TenantContext $ctx, private WhatsAppGateway $whatsapp, private SmsGateway $sms)
    {
    }

    /**
     * Queue an event for recipients. $recipients = [User|Tenant, ...]. Returns the outbox ids created.
     * Channels: in_app always for users; others as configured by the subscriber for the event.
     */
    public function dispatch(string $event, array $recipients, array $vars, CarbonImmutable $now, bool $urgent = false, ?array $escalate = null, ?object $subject = null): array
    {
        if (! isset(self::EVENTS[$event])) {
            throw new \InvalidArgumentException("Unknown event [$event].");
        }
        // Unconfigured events: in-app only, except payment reminders which go by WhatsApp + portal as spec 5.8 states.
        $config = ($this->settings->get('notifications.events') ?? [])[$event] ?? ['enabled' => true, 'channels' => str_starts_with($event, 'payment.') ? ['in_app', 'whatsapp'] : ['in_app']];
        $mandatory = in_array($event, $this->settings->get('notifications.mandatory') ?? [], true);
        if (! ($config['enabled'] ?? true) && ! $mandatory) {
            return [];
        }
        $ids = [];
        foreach ($recipients as $r) {
            $isUser = $r instanceof User;
            $channels = $config['channels'] ?? ['in_app'];
            if ($isUser) {
                $channels = array_values(array_unique(array_merge(['in_app'], $channels)));
                $prefs = ($this->settings->get('notifications.preferences', $r->id) ?? [])[$event] ?? null;
                if ($prefs !== null && ! $mandatory) {        // users may narrow non-mandatory events only
                    $channels = array_values(array_intersect($channels, array_merge(['in_app'], $prefs)));
                }
            } else {
                $channels = array_values(array_diff($channels, ['in_app', 'push']));   // tenants without an account: outbound only
            }
            $locale = $isUser ? ($this->settings->get('locale.interface', $r->id) ?? 'ar') : ($r->messages_locale ?? 'ar');
            foreach ($channels as $channel) {
                $ids[] = DB::table('notifications_outbox')->insertGetId([
                    'subscriber_id' => $this->ctx->subscriberId(), 'event' => $event, 'channel' => $channel,
                    'recipient_type' => $isUser ? 'user' : 'tenant', 'recipient_id' => $r->id, 'locale' => $locale,
                    'body' => $this->render($event, $channel, $locale, $vars), 'payload' => json_encode($vars), 'urgent' => $urgent || $event === 'maintenance.emergency',
                    'status' => 'queued', 'send_after' => $this->sendAfter($channel, $isUser, $urgent || $event === 'maintenance.emergency', $now),
                    'escalate_at' => $escalate ? $now->addHours($escalate['after_hours']) : null, 'escalate_to_role' => $escalate['to_role'] ?? null,
                    'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        return $ids;
    }

    /** Fill a template's variables in the recipient language (REQ-0360). Without a template: the event title and variables. */
    public function render(string $event, string $channel, string $locale, array $vars): string
    {
        $tpl = DB::table('notification_templates')->where('event', $event)->where('channel', $channel)->where('locale', $locale)->value('body')
            ?? DB::table('notification_templates')->where('event', $event)->where('locale', $locale)->value('body')
            ?? self::EVENTS[$event] . ($vars ? ' — ' . implode('، ', array_map(fn ($k, $v) => "$k: $v", array_keys($vars), $vars)) : '');
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? $m[0]), $tpl);
    }

    /** Quiet hours apply to WhatsApp/SMS sent to tenants, except emergencies (REQ-0357). */
    public function sendAfter(string $channel, bool $toUser, bool $urgent, CarbonImmutable $now): CarbonImmutable
    {
        if ($urgent || $toUser || ! in_array($channel, ['whatsapp', 'sms'], true)) {
            return $now;
        }
        $tz = $this->settings->get('date.timezone') ?? 'UTC';
        $q = $this->settings->get('notifications.quiet_hours');
        $local = $now->setTimezone($tz);
        [$sh, $sm] = array_map('intval', explode(':', $q['start']));
        [$eh, $em] = array_map('intval', explode(':', $q['end']));
        $minutes = $local->hour * 60 + $local->minute;
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;
        $inQuiet = $start > $end ? ($minutes >= $start || $minutes < $end) : ($minutes >= $start && $minutes < $end);
        if (! $inQuiet) {
            return $now;
        }
        $release = $local->setTime($eh, $em);
        if ($release <= $local) {
            $release = $release->addDay();
        }
        return $release->utc();
    }

    /** Deliver due items. In-app is stored; WhatsApp/SMS through their gateways; digest users get one morning message. */
    public function deliver(CarbonImmutable $now): array
    {
        $stats = [];
        $due = DB::table('notifications_outbox')->where('status', 'queued')->where('send_after', '<=', $now)->orderBy('id')->get();
        foreach ($due as $n) {
            $status = $this->deliverOne($n, $now);
            $stats[$status] = ($stats[$status] ?? 0) + 1;
        }
        return $stats;
    }

    private function deliverOne(object $n, CarbonImmutable $now): string
    {
        // Daily digest instead of scattered messages for managers/owners who chose it (REQ-0358).
        if ($n->recipient_type === 'user' && $n->channel !== 'in_app' && ! $n->urgent && $this->settings->get('notifications.daily_digest', $n->recipient_id)) {
            return $this->mark($n->id, 'in_digest');
        }
        if ($n->channel === 'in_app') {
            return $this->mark($n->id, 'sent', $now);
        }
        if (in_array($n->channel, ['whatsapp', 'sms'], true)) {
            if ($n->channel === 'whatsapp') {
                $tpl = DB::table('notification_templates')->where('event', $n->event)->where('channel', 'whatsapp')->where('locale', $n->locale)->first();
                if (! $tpl || ! $tpl->meta_approved) {
                    return $this->mark($n->id, 'blocked_template');   // Meta approval required (REQ-0360)
                }
            }
            $to = $this->phoneOf($n);
            try {
                $id = $n->channel === 'whatsapp'
                    ? $this->whatsapp->sendTemplate($this->account('whatsapp'), $to, $tpl->provider_template_name ?? $n->event, $n->locale, json_decode($n->payload, true))
                    : $this->sms->send($this->account('sms'), $to, $n->body);
            } catch (IntegrationNotConfigured) {
                return $this->mark($n->id, 'blocked_provider');
            }
            DB::table('notifications_outbox')->where('id', $n->id)->update(['provider_message_id' => $id]);
            $this->countUsage($n->channel, $now);
            return $this->mark($n->id, 'sent', $now);
        }
        return $this->mark($n->id, 'sent', $now);   // push/email handed to the Laravel notification channels
    }

    /** One morning message per user with everything held for the digest (REQ-0358). */
    public function sendDigests(CarbonImmutable $now): int
    {
        $users = DB::table('notifications_outbox')->where('status', 'in_digest')->distinct()->pluck('recipient_id');
        foreach ($users as $uid) {
            $items = DB::table('notifications_outbox')->where('status', 'in_digest')->where('recipient_id', $uid)->orderBy('id')->get();
            DB::table('notifications_outbox')->insert([
                'subscriber_id' => $this->ctx->subscriberId(), 'event' => 'approval.waiting', 'channel' => 'in_app', 'recipient_type' => 'user', 'recipient_id' => $uid,
                'locale' => 'ar', 'body' => 'ملخص اليوم: ' . $items->count() . ' بنود تحتاج انتباهك' . "\n- " . $items->pluck('body')->implode("\n- "),
                'payload' => json_encode(['digest_of' => $items->pluck('id')]), 'status' => 'sent', 'sent_at' => $now, 'send_after' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('notifications_outbox')->whereIn('id', $items->pluck('id'))->update(['status' => 'sent', 'sent_at' => $now]);
        }
        return $users->count();
    }

    /** Escalation ladder: unread/unhandled items past their deadline go to the next level (REQ-0359). */
    public function escalate(CarbonImmutable $now): int
    {
        $n = 0;
        foreach (DB::table('notifications_outbox')->whereNotNull('escalate_at')->where('escalate_at', '<=', $now)->whereNull('read_at')->where('escalation_level', 0)->get() as $item) {
            $targets = User::where('role', $item->escalate_to_role)->whereNull('archived_at')->get()->all();
            $this->dispatch($item->event, $targets, array_merge(json_decode($item->payload, true), ['escalated' => 'yes']), $now, (bool) $item->urgent);
            DB::table('notifications_outbox')->where('id', $item->id)->update(['escalation_level' => 1]);
            $n++;
        }
        return $n;
    }

    public function markRead(int $id, CarbonImmutable $now): void
    {
        DB::table('notifications_outbox')->where('id', $id)->update(['status' => 'read', 'read_at' => $now]);
    }

    /** Messages used this month per channel, against the plan quota (REQ-0361). */
    public function usage(string $month): array
    {
        return DB::table('message_usage')->where('month', $month)->pluck('count', 'channel')->map(fn ($v) => (int) $v)->all();
    }

    private function countUsage(string $channel, CarbonImmutable $now): void
    {
        DB::statement('INSERT INTO message_usage (subscriber_id, month, channel, count) VALUES (?, ?, ?, 1) ON CONFLICT (subscriber_id, month, channel) DO UPDATE SET count = message_usage.count + 1', [$this->ctx->subscriberId(), $now->format('Y-m'), $channel]);
    }

    private function mark(int $id, string $status, ?CarbonImmutable $at = null): string
    {
        DB::table('notifications_outbox')->where('id', $id)->update(['status' => $status, 'sent_at' => $at]);
        return $status;
    }

    private function phoneOf(object $n): string
    {
        return (string) ($n->recipient_type === 'tenant' ? Tenant::find($n->recipient_id)?->phone : User::find($n->recipient_id)?->phone);
    }

    private function account(string $integration): array
    {
        return \App\Models\SubscriberIntegration::where('integration', $integration)->where('active', true)->first()?->credentials ?? [];
    }
}
