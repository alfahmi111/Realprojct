<?php

namespace App\Integrations\Sms;

/** SMS layer for verification codes and critical notices (REQ-0470, REQ-0489). */
interface SmsGateway
{
    public function send(array $account, string $to, string $text): string;
}
