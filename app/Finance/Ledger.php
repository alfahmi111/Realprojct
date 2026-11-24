<?php

namespace App\Finance;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Double-entry journal for trust accounting (spec §9.3). Accounts:
 *   trust_bank                 owners' money held by the manager (separate from operations, REQ-0429)
 *   owner_direct:{owner}       rent paid straight to the owner's own account
 *   receivable:{owner}         tenant receivable for the owner's invoices
 *   deferred_rent:{owner}      rent invoiced in advance, recognised monthly (REQ-0432)
 *   rent_income:{owner}        owner's recognised rent
 *   vat_payable:{party}        output VAT of the issuer (owner:{id} or manager)
 *   deposit_liability:{lease}  deposits are liabilities, not revenue (REQ-0189, REQ-0433)
 *   fee_expense:{owner}        manager fees deducted from the owner's transfer
 *   fee_receivable:{owner}     manager fees invoiced to the owner separately (setting finance.fee.collection)
 *   expense_on_behalf:{owner}  maintenance and other costs paid for the owner
 *   manager_revenue            the manager's ONLY revenue: fees (REQ-0430)
 *   owner_payable:{owner}      net amounts transferred to the owner
 *   tenant_credit:{tenant}     unallocated payments
 * Every entry balances (database trigger), and entries are append-only: corrections are reversing entries.
 */
class Ledger
{
    public const MANAGER_REVENUE_KINDS = ['management_fee', 'tenant_finding_fee', 'renewal_fee', 'barcode_fee'];

    /** @param array<int, array{0: string, 1: Money|null, 2: Money|null, 3?: int|null}> $lines [account, debit, credit, owner_id] */
    public function post(string $date, string $kind, string $memo, array $lines, ?object $source = null, ?int $ownerId = null, ?int $propertyId = null, ?string $externalRef = null): JournalEntry
    {
        $debits = Money::sum(array_filter(array_map(fn ($l) => $l[1], $lines)));
        $credits = Money::sum(array_filter(array_map(fn ($l) => $l[2], $lines)));
        if (! $debits->equals($credits) || $debits->isZero()) {
            throw new FinanceException("Unbalanced journal entry ($debits vs $credits).");
        }
        return DB::transaction(function () use ($date, $kind, $memo, $lines, $source, $ownerId, $propertyId, $externalRef) {
            $entry = JournalEntry::create([
                'entry_date' => $date, 'kind' => $kind, 'memo_ar' => $memo, 'owner_id' => $ownerId, 'property_id' => $propertyId, 'external_ref' => $externalRef,
                'source_type' => $source ? class_basename($source) : null, 'source_id' => $source?->getKey(),
            ]);
            foreach ($lines as $l) {
                if (($l[1] === null || $l[1]->isZero()) && ($l[2] === null || $l[2]->isZero())) {
                    continue;
                }
                // a 4th element, even null, is the line's owner (M22: a reversal and a deposit's cash keep "no owner");
                // without it the line takes the entry's owner
                JournalLine::create(['journal_entry_id' => $entry->id, 'account' => $l[0], 'owner_id' => array_key_exists(3, $l) ? $l[3] : $ownerId,
                    'debit_minor' => $l[1] ?? 0, 'credit_minor' => $l[2] ?? 0]);
            }
            return $entry;
        });
    }

    /** Reverse an entry with mirrored lines (no edits, REQ-0068). */
    public function reverse(JournalEntry $entry, string $date, string $memo): JournalEntry
    {
        $lines = $entry->lines->map(fn ($l) => [$l->account, $l->credit_minor->isZero() ? null : $l->credit_minor, $l->debit_minor->isZero() ? null : $l->debit_minor, $l->owner_id])->all();
        return $this->post($date, $entry->kind . '.reversal', $memo, $lines, $entry, $entry->owner_id, $entry->property_id);
    }

    /** Balance (debit − credit) of an account, optionally up to a date. */
    public function balance(string $account, ?string $until = null): Money
    {
        $q = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.account', $account);
        if ($until) {
            $q->where('e.entry_date', '<=', $until);
        }
        $r = $q->selectRaw('coalesce(sum(l.debit_minor),0) as d, coalesce(sum(l.credit_minor),0) as c')->first();
        return Money::of((int) $r->d - (int) $r->c);
    }

    /** Sum of an account's movements in a period: ['debit' => Money, 'credit' => Money]. */
    public function movements(string $accountLike, string $from, string $to): array
    {
        $r = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account', 'like', $accountLike)->whereBetween('e.entry_date', [$from, $to])
            ->selectRaw('coalesce(sum(l.debit_minor),0) as d, coalesce(sum(l.credit_minor),0) as c')->first();
        return ['debit' => Money::of((int) $r->d), 'credit' => Money::of((int) $r->c)];
    }
}
