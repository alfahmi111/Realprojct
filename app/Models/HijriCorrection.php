<?php

namespace App\Models;

use App\Support\Dates\DateService;
use Illuminate\Database\Eloquent\Model;

/** A platform-wide manual Hijri correction for one Gregorian day (Q19, owner's decision 2026-11-23). */
class HijriCorrection extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['gregorian_date' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        $flush = fn () => DateService::forgetCorrections();
        static::saved($flush);
        static::deleted($flush);
    }

    public function hijri(): string
    {
        return sprintf('%04d-%02d-%02d', $this->hijri_year, $this->hijri_month, $this->hijri_day);
    }
}
