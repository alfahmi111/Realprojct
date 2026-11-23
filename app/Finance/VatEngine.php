<?php

namespace App\Finance;

use App\Models\Policy;
use App\Support\Money\Money;
use DateTimeInterface;

/**
 * VAT treatment (spec §9.1). The unit type never decides tax alone: usage in the contract + lessor registration +
 * track (lease / hospitality) decide it, and the resulting tax-treatment code is stored on every invoice line (REQ-0420).
 * The standard rate is read from the policies table (kind = vat_rate), not from code.
 */
class VatEngine
{
    public const EXEMPT_RESIDENTIAL = 'EXEMPT_RESIDENTIAL_RENT';     // REQ-0413
    public const STANDARD_COMMERCIAL = 'STANDARD_COMMERCIAL_RENT';   // REQ-0414
    public const OUT_OF_SCOPE_UNREGISTERED = 'OUT_OF_SCOPE_UNREGISTERED_LESSOR';   // unregistered lessor may not charge VAT
    public const STANDARD_MANAGEMENT_FEE = 'STANDARD_MANAGEMENT_FEE'; // REQ-0415
    public const STANDARD_MAINTENANCE = 'STANDARD_MAINTENANCE_RECHARGE'; // REQ-0417
    public const HOSPITALITY_PENDING = 'HOSPITALITY_NEEDS_TAX_ADVICE';  // REQ-0416 — phase 3, NEEDS-PRO-REVIEW

    /**
     * @param  string  $kind  rent | management_fee | maintenance_recharge | service
     * @param  string|null  $usage  residential | commercial (rent only)
     * @param  bool  $sellerRegistered  the issuer's VAT registration (owner for rent, manager for fees)
     * @param  string  $track  long | short
     * @return array{code: string, rate_bp: int}
     */
    public function treatment(string $kind, ?string $usage, bool $sellerRegistered, string $track, DateTimeInterface $on): array
    {
        if ($track === 'short') {
            throw new FinanceException('Hospitality (daily/weekly) VAT treatment awaits tax advice and phase 3 (REQ-0416).');
        }
        $standard = $this->standardRateBp($on);
        return match ($kind) {
            'rent' => match ($usage) {
                'residential' => ['code' => self::EXEMPT_RESIDENTIAL, 'rate_bp' => 0],
                'commercial' => $sellerRegistered ? ['code' => self::STANDARD_COMMERCIAL, 'rate_bp' => $standard] : ['code' => self::OUT_OF_SCOPE_UNREGISTERED, 'rate_bp' => 0],
                default => throw new FinanceException('Rent needs the usage in the contract (residential/commercial).'),
            },
            'management_fee' => $sellerRegistered ? ['code' => self::STANDARD_MANAGEMENT_FEE, 'rate_bp' => $standard] : ['code' => self::OUT_OF_SCOPE_UNREGISTERED, 'rate_bp' => 0],
            'maintenance_recharge', 'service' => $sellerRegistered ? ['code' => self::STANDARD_MAINTENANCE, 'rate_bp' => $standard] : ['code' => self::OUT_OF_SCOPE_UNREGISTERED, 'rate_bp' => 0],
            default => throw new FinanceException("Unknown invoice kind [$kind]."),
        };
    }

    /** Tax on a net amount (half-up to the halala). */
    public function tax(Money $net, int $rateBp): Money
    {
        return $net->percentage($rateBp);
    }

    public function standardRateBp(DateTimeInterface $on): int
    {
        $d = $on->format('Y-m-d');
        $row = Policy::where('kind', 'vat_rate')->whereNull('archived_at')->where('effective_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $d))->orderByDesc('effective_from')->first();
        if (! $row) {
            throw new FinanceException('No VAT rate in the policies table for ' . $d);
        }
        return (int) $row->payload['rate_bp'];
    }
}
