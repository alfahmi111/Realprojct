<?php

/*
 * Integration drivers (REQ-0489). "none" = provider not chosen yet → calls fail (BLOCKED for production).
 * "simulator" = tests/local only. Real providers are added only after the owner chooses them (REQ-0565…REQ-0567).
 */
return [
    'ejar' => env('INTEGRATION_EJAR', 'manual'),           // manual | automatic (decision 12 — BLOCKED)
    'einvoicing' => env('INTEGRATION_EINVOICING', 'none'),
    'payments' => env('INTEGRATION_PAYMENTS', 'none'),
    'whatsapp' => env('INTEGRATION_WHATSAPP', 'none'),
    'sms' => env('INTEGRATION_SMS', 'none'),
    'accounting' => env('INTEGRATION_ACCOUNTING', 'none'),
    'document_reader' => env('INTEGRATION_DOCUMENT_READER', 'none'),
];
