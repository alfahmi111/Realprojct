<?php

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

/**
 * Eloquent cast: integer halalas column <-> Money. Usage: 'amount' => MoneyCast::class
 * or MoneyCast::class.':currency_column'. Floats are rejected (REQ-0165, REQ-0488).
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(private ?string $currencyColumn = null)
    {
    }

    public function get($model, string $key, $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }
        $currency = $this->currencyColumn ? ($attributes[$this->currencyColumn] ?? 'SAR') : 'SAR';
        return Money::of((int) $value, $currency);
    }

    public function set($model, string $key, $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Money) {
            return $value->minor();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new InvalidArgumentException("Money attribute [$key] accepts Money or integer halalas only.");
    }
}
