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
            return app(PaymentService::class)->record(Tenant::findOrFail($data['tenant_id']), Money::of((int) $data['amount_minor']), $data['method'], CarbonImmutable::parse($data['received_on']), $data['paid_to'], auth()->user(), $data['reference'] ?? null);
        } catch (\App\Finance\FinanceException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['data.amount_minor' => $e->getMessage()]);
        }
    }
}
