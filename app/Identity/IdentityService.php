<?php

namespace App\Identity;

use App\Integrations\Identity\IdentityGateway;
use App\Integrations\IntegrationNotConfigured;
use App\Models\IdentityVerification;
use App\Models\Owner;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The identity layer, built once (REQ-0472, owner order Q30):
 *  - Yaqeen: a person's official name by national id (REQ-0446) — tenants and owners, at lease creation.
 *  - Wathq: a commercial registration's name, status and expiry (REQ-0447) — company tenants, vendors, the office.
 *  - Nafath: the person approves in the Nafath app by picking the shown number (REQ-0445, REQ-0471).
 * The result is "verified" only when the official name matches the name on file (Arabic normalised); otherwise
 * "mismatch" with the official name shown, so the manager corrects the record — nothing is changed silently (ر-01).
 */
final class IdentityService
{
    public const SOURCE_AR = ['nafath' => 'نفاذ', 'yaqeen' => 'يقين', 'wathq' => 'واثق'];

    public const STATUS_AR = ['verified' => 'موثّق', 'mismatch' => 'الاسم لا يطابق السجل الرسمي', 'not_found' => 'غير موجود في السجل', 'pending' => 'بانتظار الموافقة في نفاذ',
        'rejected' => 'رُفض في نفاذ', 'expired' => 'السجل التجاري منتهٍ أو موقوف'];

    public function __construct(private IdentityGateway $gateway)
    {
    }

    public function available(string $source): bool
    {
        return in_array(\App\Integrations\IntegrationStatus::driver($source), ['simulator', 'live'], true);
    }

    private function ensure(string $source): void
    {
        if (! $this->available($source)) {
            throw new IntegrationNotConfigured('لم يُربط مزوّد ' . self::SOURCE_AR[$source] . ' بعد.');
        }
    }

    /** Yaqeen for a tenant or owner (individual). */
    public function yaqeen(Tenant|Owner $person, User $by, ?string $birthDate = null): IdentityVerification
    {
        $this->ensure('yaqeen');
        $nid = (string) $person->national_id;
        if (! preg_match('/^[12]\d{9}$/', $nid)) {
            throw new \InvalidArgumentException('رقم الهوية غير مسجّل أو غير صحيح (10 أرقام يبدأ بـ 1 أو 2).');
        }
        $r = $this->gateway->yaqeenPerson($nid, $birthDate, $person->name_ar);
        $status = ! $r['found'] ? 'not_found' : ($this->sameName($r['name_ar'], $person->name_ar) ? 'verified' : 'mismatch');
        return $this->record($person, 'yaqeen', $status, $this->mask($nid), $r['name_ar'], [], $by);
    }

    /** Wathq for a company tenant, a vendor or the office itself. */
    public function wathq(Tenant|Owner|Vendor|Subscriber $org, User $by): IdentityVerification
    {
        $this->ensure('wathq');
        $cr = (string) $org->commercial_registration;
        if (! preg_match('/^\d{10}$/', $cr)) {
            throw new \InvalidArgumentException('السجل التجاري غير مسجّل أو غير صحيح (10 أرقام).');
        }
        $r = $this->gateway->wathqCompany($cr, $org->name_ar);
        $status = match (true) {
            ! $r['found'] => 'not_found',
            $r['status'] !== 'active' => 'expired',
            ! $this->sameName($r['name_ar'], $org->name_ar) => 'mismatch',
            default => 'verified',
        };
        return $this->record($org, 'wathq', $status, 'CR ' . $this->mask($cr), $r['name_ar'], ['cr_status' => $r['status'], 'cr_expires_on' => $r['expires_on']], $by);
    }

    /** Nafath step 1: returns the number to show; the person picks it in the Nafath app. */
    public function nafathStart(Tenant|Owner $person, User $by): array
    {
        $this->ensure('nafath');
        $nid = (string) $person->national_id;
        if (! preg_match('/^[12]\d{9}$/', $nid)) {
            throw new \InvalidArgumentException('رقم الهوية غير مسجّل أو غير صحيح.');
        }
        $r = $this->gateway->nafathRequest($nid);
        $v = $this->record($person, 'nafath', 'pending', $this->mask($nid), null, ['request_id' => $r['request_id']], $by, verifiedAt: false);
        Cache::put('nafath:' . $v->id, $r['request_id'], now()->addMinutes(5));
        return ['verification_id' => $v->id, 'random' => $r['random']];
    }

    /** Nafath step 2: check whether the person approved. */
    public function nafathCheck(IdentityVerification $v, Tenant|Owner $person): IdentityVerification
    {
        $this->ensure('nafath');
        $r = $this->gateway->nafathStatus($v->details['request_id'] ?? '', $person->name_ar);
        $status = match ($r['status']) {
            'approved' => $this->sameName($r['name_ar'], $person->name_ar) ? 'verified' : 'mismatch',
            'pending' => 'pending',
            default => 'rejected',
        };
        $v->forceFill(['status' => $status, 'returned_name' => $r['name_ar'], 'verified_at' => $status === 'pending' ? null : CarbonImmutable::now()])->save();
        return $v;
    }

    /** Latest finished check of a subject, for the «موثّق» badge. */
    public function latest(Model $subject): ?IdentityVerification
    {
        return IdentityVerification::where('subject_type', self::type($subject))->where('subject_id', $subject->getKey())
            ->where('status', '<>', 'pending')->latest('id')->first();
    }

    public static function type(Model $m): string
    {
        return match (true) {
            $m instanceof Tenant => 'tenant', $m instanceof Owner => 'owner', $m instanceof Vendor => 'vendor', $m instanceof Subscriber => 'subscriber',
        };
    }

    /** Arabic names compared after removing diacritics, tatweel, hamza forms, taa marbuta and extra spaces. */
    public function sameName(?string $a, ?string $b): bool
    {
        $n = function (?string $s) {
            $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', (string) $s);
            $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
            return trim(preg_replace('/\s+/u', ' ', $s));
        };
        return $a !== null && $b !== null && $n($a) !== '' && $n($a) === $n($b);
    }

    private function mask(string $id): string
    {
        return str_repeat('*', max(0, mb_strlen($id) - 4)) . mb_substr($id, -4);
    }

    private function record(Model $subject, string $source, string $status, string $ref, ?string $name, array $details, User $by, bool $verifiedAt = true): IdentityVerification
    {
        return IdentityVerification::create(['subject_type' => self::type($subject), 'subject_id' => $subject->getKey(), 'source' => $source, 'status' => $status,
            'reference_masked' => $ref, 'returned_name' => $name, 'details' => $details, 'verified_by' => $by->id, 'verified_at' => $verifiedAt ? CarbonImmutable::now() : null]);
    }
}
