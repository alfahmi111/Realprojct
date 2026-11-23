<?php

namespace App\Integrations\Sadad;

/**
 * SADAD bills (REQ-0467): a bill number the tenant pays from any bank app. The money goes to the biller account of
 * the office or owner, never to the platform (REQ-0436). Real driver added when the owner chooses a biller service.
 */
interface SadadGateway
{
    /** @param array{number:string, amount_minor:int, due_on:string, customer:string} $invoice  @return array{bill_number:string} */
    public function issueBill(array $invoice): array;
}
