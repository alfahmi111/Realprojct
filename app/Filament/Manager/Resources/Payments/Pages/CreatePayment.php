<?php

namespace App\Filament\Manager\Resources\Payments\Pages;

use App\Filament\Manager\Resources\Payments\PaymentResource;
use App\Finance\PaymentService;
use App\Models\Tenant;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    /** Recorded through the payment service: receipt number, FIFO allocation, trust ledger (REQ-0188, REQ-0316). */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PaymentService::class)->record(Tenant::findOrFail($data['tenant_id']), Money::of((int) $data['amount_minor']), $data['method'], CarbonImmutable::parse($data['received_on']), $data['paid_to'], auth()->user(), $data['reference'] ?? null, 'manual', $data['ref_source'] ?? null);
        } catch (\App\Finance\DuplicatePaymentException $e) {   // M22: the same outside transaction, already recorded
            throw \Illuminate\Validation\ValidationException::withMessages(['data.reference' => $e->getMessage()]);
        } catch (\App\Finance\FinanceException $e) {
            if (str_contains($e->getMessage(), 'مصدر المرجع')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['data.ref_source' => $e->getMessage()]);
            }
            throw \Illuminate\Validation\ValidationException::withMessages(['data.amount_minor' => \App\Support\Messages::ar($e->getMessage())]);
        }
    }

    /** Q38: say plainly when the payment was kept waiting for its reference instead of recorded. */
    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->record?->status === 'pending_match'
            ? 'حُفظت الدفعة بانتظار المطابقة: لا سند قبض ولا أثر مالي حتى يُدخل رقم العملية ومصدره من قائمة الدفعات.'
            : 'سُجّلت الدفعة وصدر سند القبض';
    }
}
