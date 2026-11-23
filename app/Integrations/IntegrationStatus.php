<?php

namespace App\Integrations;

use App\Models\SubscriberIntegration;

/**
 * What the office sees of each external integration (M14, plan approved 2026-11-23): the platform driver from
 * config/integrations.php and whether this office has its own account linked (subscriber_integrations, REQ-0436).
 * States are stated plainly; a simulator is never shown as a live connection.
 */
final class IntegrationStatus
{
    /** key => [name, purpose, phase] */
    public const ITEMS = [
        'ejar' => ['إيجار', 'توثيق عقود الإيجار', 'MVP'],
        'einvoicing' => ['الفوترة الإلكترونية (زاتكا)', 'ختم الفواتير وإبلاغ الهيئة', 'MVP'],
        'payments' => ['بوابة الدفع', 'دفع المستأجر بمدى وApple Pay والبطاقات', 'MVP'],
        'whatsapp' => ['واتساب للأعمال', 'التذكيرات والإشعارات', 'MVP'],
        'sms' => ['الرسائل النصية', 'رموز التحقق والإشعارات الحرجة', 'MVP'],
        'accounting' => ['برامج المحاسبة', 'تصدير القيود إلى قيود أو Zoho أو Odoo', 'MVP'],
        'document_reader' => ['قراءة المستندات', 'الانتقال السريع واستيراد عقود إيجار', 'MVP'],
        'national_address' => ['العنوان الوطني (سبل)', 'جلب العنوان الوطني المختصر من موقع المبنى على الخريطة', 'MVP'],
        'nafath' => ['نفاذ', 'الدخول والتحقق من الهوية', 'P2'],
        'yaqeen' => ['يقين', 'التحقق من هوية الأفراد', 'P2'],
        'wathq' => ['واثق', 'التحقق من السجلات التجارية', 'P2'],
        'sadad' => ['سداد', 'فواتير برقم سداد', 'P2'],
    ];

    public const STATE_AR = [
        'manual' => ['يدوي بمساعدة النظام', 'info'],
        'simulator' => ['محاكاة للتجربة فقط', 'warning'],
        'none' => ['غير مربوط', 'gray'],
        'live' => ['مربوط', 'success'],
    ];

    public static function driver(string $key): string
    {
        $d = (string) config("integrations.$key", 'none');
        return match ($d) {
            'manual', 'simulator', 'none' => $d,
            '' => 'none',
            default => 'live',
        };
    }

    /** @return list<array{key:string,name:string,purpose:string,phase:string,state:string,state_ar:string,color:string,account:?string}> */
    public static function all(): array
    {
        $accounts = SubscriberIntegration::where('active', true)->pluck('account_holder_name', 'integration');
        $out = [];
        foreach (self::ITEMS as $key => [$name, $purpose, $phase]) {
            $state = self::driver($key);
            $out[] = ['key' => $key, 'name' => $name, 'purpose' => $purpose, 'phase' => $phase, 'state' => $state,
                'state_ar' => self::STATE_AR[$state][0], 'color' => self::STATE_AR[$state][1], 'account' => $accounts[$key] ?? null];
        }
        return $out;
    }
}
