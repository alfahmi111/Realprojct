<?php

namespace App\Compliance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which expiry alert is due (spec 6.7: 30 and 7 days before) — by range, not by exact day (system review M18), so a day
 * the daily job missed, or a document uploaded with 5 days left, still gets its alert; each stage is sent once per
 * subject, recorded in the outbox payload under «التنبيه».
 */
final class ExpiryStage
{
    public const LABELS = [30 => 'قبل الانتهاء بثلاثين يوماً', 7 => 'قبل الانتهاء بسبعة أيام'];

    /** 7, 30 or null (more than 30 days left, or already expired). */
    public static function due(CarbonImmutable $today, \DateTimeInterface $expiresOn): ?int
    {
        $days = (int) $today->startOfDay()->diffInDays(CarbonImmutable::parse($expiresOn->format('Y-m-d')), false);
        return match (true) {
            $days < 0 => null,
            $days <= 7 => 7,
            $days <= 30 => 30,
            default => null,
        };
    }

    public static function alreadySent(string $event, object $subject, int $stage): bool
    {
        return DB::table('notifications_outbox')->where('event', $event)->where('subject_type', class_basename($subject))
            ->where('subject_id', (string) $subject->getKey())->whereRaw("payload->>'التنبيه' = ?", [self::LABELS[$stage]])->exists();
    }
}
