<?php

namespace App\Integrations;

use App\Audit\AuditLogger;
use App\Models\Owner;
use App\Models\Subscriber;
use App\Models\SubscriberIntegration;
use App\Tenancy\TenantContext;

/**
 * The office's own provider accounts (REQ-0372, §7.6و): payment gateway merchant account, e-invoicing, WhatsApp, SMS,
 * accounting software, document reader. Each account is in the office's name or one of its owners' — never the
 * platform's, because the platform receives no money (REQ-0436). Credentials are stored encrypted and never shown back:
 * the screen says «محفوظ» and a new value replaces the old one. Every change is audited without the secrets.
 */
final class IntegrationAccounts
{
    public const KINDS = ['payments' => 'بوابة الدفع', 'einvoicing' => 'الفوترة الإلكترونية', 'whatsapp' => 'واتساب للأعمال', 'sms' => 'الرسائل النصية',
        'accounting' => 'برنامج المحاسبة', 'document_reader' => 'قراءة المستندات'];

    public function __construct(private TenantContext $ctx)
    {
    }

    /** Names an account may be held in: the office and its owners. */
    public function holders(): array
    {
        $sid = $this->ctx->subscriberId();
        $office = $sid ? $this->ctx->run(null, fn () => Subscriber::find($sid)?->name_ar) : null;
        return array_values(array_unique(array_filter(array_merge([$office], Owner::whereNull('archived_at')->orderBy('name_ar')->pluck('name_ar')->all()))));
    }

    /** @param array<string,string> $credentials  an empty array keeps the saved credentials */
    public function save(string $kind, string $holder, array $credentials, bool $active): SubscriberIntegration
    {
        if (! isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('نوع التكامل غير معروف.');
        }
        $holder = trim($holder);
        if (! in_array($holder, $this->holders(), true)) {
            throw new \RuntimeException('الحساب يكون باسم المكتب أو أحد ملاكه، ولا يكون باسم المنصة؛ المنصة لا تستلم أموالاً.');
        }
        $credentials = array_filter(array_map(fn ($v) => trim((string) $v), $credentials), fn ($v) => $v !== '');
        $row = SubscriberIntegration::firstOrNew(['integration' => $kind]);
        if (! $row->exists && ! $credentials) {
            throw new \RuntimeException('أدخل بيانات الحساب من لوحة المزوّد (المفتاح، رقم التاجر…).');
        }
        $row->fill(['account_holder_name' => $holder, 'active' => $active] + ($credentials ? ['credentials' => $credentials] : []))->save();
        AuditLogger::record('integration.account_saved', $row, null, ['integration' => $kind, 'holder' => $holder, 'active' => $active, 'credentials_replaced' => (bool) $credentials, 'keys' => array_keys($credentials)]);
        return $row;
    }

    /** @return list<array{kind:string,name:string,holder:?string,active:bool,keys:list<string>}> */
    public function list(): array
    {
        $rows = SubscriberIntegration::all()->keyBy('integration');
        $out = [];
        foreach (self::KINDS as $k => $name) {
            $r = $rows[$k] ?? null;
            $out[] = ['kind' => $k, 'name' => $name, 'holder' => $r?->account_holder_name, 'active' => (bool) $r?->active, 'keys' => $r ? array_keys((array) $r->credentials) : []];
        }
        return $out;
    }
}
