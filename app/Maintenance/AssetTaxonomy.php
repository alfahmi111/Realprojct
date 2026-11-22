<?php

namespace App\Maintenance;

/** Unified simplified asset taxonomy, after Uniformat (spec §6.1, REQ-0212…REQ-0219). */
final class AssetTaxonomy
{
    public const CATEGORIES = [
        'hvac' => ['ar' => 'تكييف وتهوية', 'types' => ['window_ac' => 'مكيف شباك', 'split_ac' => 'سبليت', 'central_ac' => 'مركزي', 'exhaust_fan' => 'مراوح شفط'], 'req' => 'REQ-0213'],
        'plumbing' => ['ar' => 'سباكة', 'types' => ['water_heater' => 'سخانات', 'pump' => 'مضخات', 'tank' => 'خزانات', 'filter' => 'فلاتر'], 'req' => 'REQ-0214'],
        'electrical' => ['ar' => 'كهرباء', 'types' => ['panel' => 'لوحات', 'generator' => 'مولد', 'lighting' => 'إنارة'], 'req' => 'REQ-0215'],
        'safety' => ['ar' => 'سلامة', 'types' => ['extinguisher' => 'طفايات', 'fire_alarm' => 'إنذار حريق', 'sprinkler' => 'رشاشات', 'camera' => 'كاميرات'], 'req' => 'REQ-0216'],
        'vertical_transport' => ['ar' => 'نقل رأسي', 'types' => ['elevator' => 'مصاعد'], 'req' => 'REQ-0217'],
        'appliances' => ['ar' => 'أجهزة', 'types' => ['kitchen' => 'مطبخ', 'washer' => 'غسالة', 'fridge' => 'ثلاجة'], 'req' => 'REQ-0218'],
        'structural' => ['ar' => 'إنشائي', 'types' => ['door' => 'أبواب', 'window' => 'نوافذ', 'insulation' => 'عزل', 'paint' => 'دهانات'], 'req' => 'REQ-0219'],
    ];

    public static function valid(string $category, string $type): bool
    {
        return isset(self::CATEGORIES[$category]['types'][$type]);
    }
}
