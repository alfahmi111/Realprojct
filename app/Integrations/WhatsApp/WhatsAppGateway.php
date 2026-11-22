<?php

namespace App\Integrations\WhatsApp;

/** WhatsApp Business official API layer (REQ-0468, REQ-0489). Templates need Meta approval (REQ-0360). */
interface WhatsAppGateway
{
    public function sendTemplate(array $account, string $to, string $template, string $language, array $variables): string;
}
