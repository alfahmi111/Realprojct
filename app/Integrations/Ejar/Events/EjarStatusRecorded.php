<?php

namespace App\Integrations\Ejar\Events;

use Illuminate\Foundation\Events\Dispatchable;

class EjarStatusRecorded
{
    use Dispatchable;

    public function __construct(public string $leaseId, public string $status, public ?string $ejarContractNumber)
    {
    }
}
