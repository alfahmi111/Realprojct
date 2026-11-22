<?php

namespace App\Filament\Support;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;

/**
 * 2FA by authenticator app (REQ-0445). A wrong code from an account that has no recovery codes yet must show
 * "invalid code" — never a server error (Filament throws a LogicException when the recovery list is empty).
 */
class WithaqAppAuthentication extends AppAuthentication
{
    public function getRecoveryCodes(HasAppAuthenticationRecovery $user): array
    {
        $codes = $user->getAppAuthenticationRecoveryCodes();

        return blank($codes) ? [] : $codes;
    }
}
