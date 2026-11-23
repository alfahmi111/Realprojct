<?php

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money amount stored as integer minor units (halalas for SAR) — REQ-0165, REQ-0488.
 * No floating point anywhere: rates are expressed in basis points (1% = 100 bp) or as integer
 * ratios, and every division states its rounding mode explicitly.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(private readonly int $minor, private readonly string $currency)
    {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid currency code [$currency].");
        }
    }

    public static function of(int $minor, string $currency = 'SAR'): self
    {
        return new self($minor, $currency);
    }

    public static function zero(string $currency = 'SAR'): self
    {
        return new self(0, $currency);
    }

    /**
     * Parse a decimal string such as "1250.50" or "-3.5" into minor units without using floats.
     * Rejects more fractional digits than the currency has (no silent rounding of input).
     */
    public static function parse(string $amount, string $currency = 'SAR'): self
    {
        $amount = trim(strtr($amount, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', ',' => '', '٬' => '']));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Invalid amount [$amount].");
        }
        $minor = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return new self($m[1] === '-' ? -$minor : $minor, $currency);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->checkedAdd($this->minor, $other->minor), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->checkedAdd($this->minor, -$other->minor), $this->currency);
    }

    public function times(int $factor): self
    {
        $r = $this->minor * $factor;
        if (! is_int($r)) {
            throw new \OverflowException('Money overflow.');
        }
        return new self($r, $this->currency);
    }

    /** Multiply by numerator/denominator with explicit rounding (integers only). */
    public function multiplyRatio(int $numerator, int $denominator, Rounding $rounding = Rounding::HalfUp): self
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }
        return new self(self::divide($this->minor * $numerator, $denominator, $rounding), $this->currency);
    }

    /** Percentage in basis points: 1500 bp = 15%. */
    public function percentage(int $basisPoints, Rounding $rounding = Rounding::HalfUp): self
    {
        return $this->multiplyRatio($basisPoints, 10000, $rounding);
    }

    /**
     * Split by integer ratios so that the parts always add up exactly to the original amount
     * (largest-remainder method; leftover halalas go to the earliest parts with the largest remainders).
     *
     * @param  int[]  $ratios
     * @return self[]
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);
        if ($total <= 0 || min($ratios) < 0) {
            throw new InvalidArgumentException('Ratios must be non-negative with a positive sum.');
        }
        $sign = $this->minor < 0 ? -1 : 1;
        $abs = abs($this->minor);
        $parts = [];
        $remainders = [];
        foreach ($ratios as $i => $ratio) {
            $parts[$i] = intdiv($abs * $ratio, $total);
            $remainders[$i] = ($abs * $ratio) % $total;
        }
        $left = $abs - array_sum($parts);
        arsort($remainders, SORT_NUMERIC);
        foreach (array_keys($remainders) as $i) {
            if ($left-- <= 0) {
                break;
            }
            $parts[$i]++;
        }
        ksort($parts);
        return array_map(fn (int $p) => new self($sign * $p, $this->currency), $parts);
    }

    /** Split into n equal parts that add up exactly. */
    public function split(int $n): array
    {
        if ($n <= 0) {
            throw new InvalidArgumentException('n must be positive.');
        }
        return $this->allocate(array_fill(0, $n, 1));
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);
        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public static function min(self $a, self $b): self
    {
        return $a->compare($b) <= 0 ? $a : $b;
    }

    /** @param self[] $amounts */
    public static function sum(array $amounts, string $currency = 'SAR'): self
    {
        return array_reduce($amounts, fn (self $c, self $m) => $c->plus($m), self::zero($currency));
    }

    /** Plain decimal string with two digits, e.g. "1250.50" — for exports and e-invoice payloads. */
    public function toDecimalString(): string
    {
        $abs = abs($this->minor);
        return ($this->minor < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->toDecimalString() . ' ' . $this->currency;
    }

    public function jsonSerialize(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency];
    }

    /** Integer division with an explicit rounding mode. */
    public static function divide(int $dividend, int $divisor, Rounding $rounding): int
    {
        $q = intdiv($dividend, $divisor);
        $r = $dividend % $divisor;
        if ($r === 0) {
            return $q;
        }
        $positive = ($dividend < 0) === ($divisor < 0);
        $twice = abs($r) * 2;
        $absDivisor = abs($divisor);
        return match ($rounding) {
            Rounding::Down => $positive ? $q : $q - 1,          // toward −∞
            Rounding::Up => $positive ? $q + 1 : $q,            // toward +∞
            Rounding::TowardZero => $q,
            Rounding::HalfUp => $twice >= $absDivisor ? ($positive ? $q + 1 : $q - 1) : $q,   // half away from zero
            Rounding::HalfEven => $twice > $absDivisor || ($twice === $absDivisor && $q % 2 !== 0)
                ? ($positive ? $q + 1 : $q - 1) : $q,
        };
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }

    private function checkedAdd(int $a, int $b): int
    {
        $r = $a + $b;
        if (! is_int($r)) {
            throw new \OverflowException('Money overflow.');
        }
        return $r;
    }
}
