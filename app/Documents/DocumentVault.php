<?php

namespace App\Documents;

use App\Maintenance\MaintenanceService;
use App\Models\Building;
use App\Models\Document;
use App\Models\DocumentView;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Space;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The document vault (REQ-0054, REQ-0265…REQ-0270; owner order Q30):
 *  - a classified file per property, building, unit and party, each with an expiry date when it has one;
 *  - ownership, licences, statutory certificates, parties — party documents (IDs, CRs) only for users with
 *    documents.parties (personal data protection, REQ-0269);
 *  - replacing a document keeps the earlier version; every view is logged with who and when;
 *  - 30 and 7 days before expiry the managers are alerted, and an expiring certificate opens one statutory work order.
 */
final class DocumentVault
{
    /** kind => [category, Arabic name] — the lists of 6.7. */
    public const KINDS = [
        'deed' => ['ownership', 'الصك'],
        'real_estate_registry' => ['ownership', 'شهادة التسجيل العيني'],
        'power_of_attorney' => ['ownership', 'الوكالة الشرعية للمدير'],
        'building_permit' => ['license', 'رخصة البناء'],
        'municipal_license' => ['license', 'الرخصة البلدية'],
        'billboard_license' => ['license', 'رخصة اللوحة الإعلانية'],
        'hospitality_license' => ['license', 'ترخيص الضيافة'],
        'ad_license' => ['license', 'ترخيص الإعلان العقاري'],
        'civil_defense' => ['certificate', 'شهادة الدفاع المدني'],
        'elevator_inspection' => ['certificate', 'شهادة فحص المصاعد'],
        'building_insurance' => ['certificate', 'وثيقة تأمين المبنى'],
        'national_id_copy' => ['party', 'صورة الهوية'],
        'cr_copy' => ['party', 'صورة السجل التجاري'],
        'other' => ['ownership', 'مستند آخر'],
    ];

    public const CATEGORY_AR = ['ownership' => 'ملكية', 'license' => 'رخص', 'certificate' => 'شهادات نظامية', 'party' => 'أطراف'];

    public const ALERT_DAYS = [30, 7];

    public function __construct(private MaintenanceService $maintenance)
    {
    }

    public static function typeOf(Model $m): string
    {
        return match (true) {
            $m instanceof Property => 'property', $m instanceof Building => 'building', $m instanceof Space => 'space',
            $m instanceof Owner => 'owner', $m instanceof Tenant => 'tenant',
        };
    }

    public function canSee(User $user, Document $doc): bool
    {
        return $doc->category !== 'party' || $user->hasPermission('documents.parties');
    }

    public function add(Model $owner, string $kind, string $title, string $path, ?string $expiresOn, User $by, ?string $mime = null): Document
    {
        if (! isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException("Unknown document kind [$kind].");
        }
        $category = self::KINDS[$kind][0];
        if ($category === 'party' && ! $by->hasPermission('documents.parties')) {
            throw new \RuntimeException('مستندات الأطراف (الهويات والسجلات) لمن له صلاحية الاطلاع عليها فقط.');
        }
        return Document::create([
            'documentable_type' => self::typeOf($owner), 'documentable_id' => $owner->getKey(), 'property_id' => $this->propertyOf($owner),
            'category' => $category, 'kind' => $kind, 'title_ar' => $title ?: self::KINDS[$kind][1], 'file_path' => $path, 'mime' => $mime,
            'expires_on' => $expiresOn ?: null, 'version' => 1, 'uploaded_by' => $by->id,
        ]);
    }

    /** A new version (renewed licence, new certificate): the earlier one is kept, marked superseded. */
    public function replace(Document $old, string $path, ?string $expiresOn, User $by, ?string $mime = null): Document
    {
        if ($old->superseded_at) {
            throw new \RuntimeException('هذه نسخة سابقة؛ استبدل النسخة الحالية.');
        }
        return DB::transaction(function () use ($old, $path, $expiresOn, $by, $mime) {
            $old->forceFill(['superseded_at' => now()])->save();
            return Document::create([
                'documentable_type' => $old->documentable_type, 'documentable_id' => $old->documentable_id, 'property_id' => $old->property_id,
                'category' => $old->category, 'kind' => $old->kind, 'title_ar' => $old->title_ar, 'file_path' => $path, 'mime' => $mime,
                'expires_on' => $expiresOn ?: null, 'version' => $old->version + 1, 'replaces_id' => $old->id, 'uploaded_by' => $by->id,
            ]);
        });
    }

    /** Log the view and return the stored path; refuses party documents without the permission. */
    public function open(Document $doc, User $user, string $action = 'view'): string
    {
        if (! $this->canSee($user, $doc)) {
            throw new \RuntimeException('لا تملك صلاحية الاطلاع على مستندات الأطراف.');
        }
        DocumentView::create(['document_id' => $doc->id, 'user_id' => $user->id, 'action' => $action, 'viewed_at' => now()]);
        return $doc->file_path;
    }

    /** @return list<Document> earlier versions, newest first */
    public function history(Document $doc): array
    {
        $out = [];
        $cur = $doc;
        while ($cur->replaces_id && ($prev = Document::find($cur->replaces_id))) {
            $out[] = $prev;
            $cur = $prev;
        }
        return $out;
    }

    /**
     * Daily, inside the subscriber's context: alert managers 30 and 7 days before; an expiring certificate opens one
     * statutory work order (REQ-0270). @return array{alerted:int, work_orders:int}
     */
    public function alerts(CarbonImmutable $today, \App\Notifications\NotificationCenter $center): array
    {
        $alerted = 0;
        $wos = 0;
        $managers = null;
        // by range with a once-per-stage record (review M18): a missed day or a late upload still gets its alert
        foreach (Document::current()->whereNotNull('expires_on')->whereBetween('expires_on', [$today->toDateString(), $today->addDays(max(self::ALERT_DAYS))->toDateString()])->get() as $doc) {
            $stage = \App\Compliance\ExpiryStage::due($today, $doc->expires_on);
            if ($stage === null || \App\Compliance\ExpiryStage::alreadySent('document.expiring', $doc, $stage)) {
                continue;
            }
            $managers ??= User::where('role', 'manager')->whereNull('archived_at')->get()->all();
            $center->dispatch('document.expiring', $managers, ['المستند' => $doc->title_ar, 'تاريخ الانتهاء' => $doc->expires_on->format('Y-m-d'), 'التنبيه' => \App\Compliance\ExpiryStage::LABELS[$stage]], $today->setTime(4, 0), urgent: true, subject: $doc);
            $alerted++;
            if ($doc->category === 'certificate' && $doc->statutory_work_order_id === null && $doc->property_id) {
                $wo = $this->maintenance->reportStatutory($doc);
                $doc->forceFill(['statutory_work_order_id' => $wo->id])->save();
                $wos++;
            }
        }
        return ['alerted' => $alerted, 'work_orders' => $wos];
    }

    private function propertyOf(Model $m): ?int
    {
        return match (true) {
            $m instanceof Property => $m->id,
            $m instanceof Building, $m instanceof Space => (int) $m->property_id,
            default => null,
        };
    }
}
