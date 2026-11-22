<?php

namespace App\Integrations\DocumentReader;

/**
 * AI document reading layer (OCR + language model) for quick migration (REQ-0469, REQ-0122, REQ-0484).
 * Processing must stay in the Kingdom or have the customer's explicit consent (owner task REQ-0567).
 */
interface DocumentReaderGateway
{
    /** Returns extracted fields, each with a confidence score: [['field' => ..., 'value' => ..., 'confidence' => 0..100], ...] */
    public function extract(string $path, string $kind): array;
}
