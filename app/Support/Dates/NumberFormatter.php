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
        if ($neg) {
            $text = "\u{2066}{$text}\u{2069}";   // LRI…PDI isolate: the minus stays at the start of the number inside Arabic text
        }
        $symbol = self::currencySymbol($money->currency(), $currencyDisplay);
        return $symbol === null ? $text : "$text $symbol";
    }

    /**
     * An amount for the signed-in user's screen, in their own digits and currency display (REQ-0363, REQ-0364).
     * In Arabic text the number comes first in reading order, so the sign appears to its left with a space, as the
     * Saudi Central Bank's usage guide requires.
     */
    public static function money(Money|int|null $money, ?int $userId = null): string
    {
        if ($money === null) {
            return '—';
        }
        [$digits, $display] = self::preferences($userId);
        return self::amount($money instanceof Money ? $money : Money::of($money), $digits, $display);
    }

    /** The currency mark alone, for field suffixes and labels ("سعر الوحدة (⃁)"). */
    public static function currencyLabel(?int $userId = null): string
    {
        return self::currencySymbol('SAR', self::preferences($userId)[1]) ?? 'SAR';
    }

    private static function preferences(?int $userId): array
    {
        $userId ??= auth()->id();
        try {
            $settings = app(\App\Settings\Settings::class);
            return [$settings->get('numbers.digits', $userId) ?? 'western', $settings->get('numbers.currency_display', $userId) ?? 'symbol'];
        } catch (\Throwable) {
            return ['western', 'symbol'];
        }
    }

    /**
     * Currency display (setting numbers.currency_display): symbol = the official Saudi riyal sign U+20C1 (Unicode 17,
     * Saudi Central Bank 2025-02-20; the platform ships its glyph: public/fonts/saudi-riyal.ttf, built by tools/build_riyal_font.php) | abbreviation (ر.س) | code.
     * Not U+FDFC "﷼": that is an old compatibility character that renders the word «ریال», not the sign.
     * Gulf currencies are shown by ISO code until the expansion phase (REQ-0364).
     */
    public static function currencySymbol(string $currency, string $display): ?string
    {
        if ($currency !== 'SAR') {
            return $currency;
        }
        return match ($display) {
            'symbol' => "\u{20C1}",
            'abbreviation' => 'ر.س',
            'code' => 'SAR',
            'none' => null,
            default => throw new \InvalidArgumentException("Unknown currency display [$display]."),
        };
    }
}
