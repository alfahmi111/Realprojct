<?php

namespace App\Support\Dates;

use App\Support\Money\Money;

/**
 * Numbers and currency display (REQ-0363, REQ-0364): Western (1 2 3) or Eastern Arabic (١ ٢ ٣) digits,
 * currency as the new riyal symbol or "ر.س". Display only — never used to store values.
 */
final class NumberFormatter
{
    private const EASTERN = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    public static function digits(string $text, string $digits): string
    {
        return match ($digits) {
            'western' => strtr($text, array_flip(self::EASTERN)),
            'eastern' => strtr($text, array_combine(range(0, 9), self::EASTERN)),
            default => throw new \InvalidArgumentException("Unknown digits style [$digits]."),
        };
    }

    /** Group thousands without floats: 125050 halalas -> "1,250.50". */
    public static function amount(Money $money, string $digits = 'western', string $currencyDisplay = 'code'): string
    {
        $dec = $money->toDecimalString();
        $neg = str_starts_with($dec, '-');
        [$int, $frac] = explode('.', ltrim($dec, '-'));
        $int = strrev(implode(',', str_split(strrev($int), 3)));
        $text = ($neg ? '-' : '') . $int . '.' . $frac;
        if ($digits === 'eastern') {
            $text = strtr(self::digits($text, 'eastern'), [',' => '٬', '.' => '٫']);
        }
        $symbol = self::currencySymbol($money->currency(), $currencyDisplay);
        return $symbol === null ? $text : "$text $symbol";
    }

    /**
     * Currency display (setting numbers.currency_display): symbol (﷼ as written in spec 7.6هـ) | abbreviation (ر.س) | code.
     * Gulf currencies are shown by ISO code until the expansion phase (REQ-0364).
     */
    public static function currencySymbol(string $currency, string $display): ?string
    {
        if ($currency !== 'SAR') {
            return $currency;
        }
        return match ($display) {
            'symbol' => '﷼',
            'abbreviation' => 'ر.س',
            'code' => 'SAR',
            'none' => null,
            default => throw new \InvalidArgumentException("Unknown currency display [$display]."),
        };
    }
}
