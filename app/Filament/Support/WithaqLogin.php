<?php

namespace App\Filament\Support;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * One app, one door (REQ-0271): when the password is right but the account belongs to another role interface,
 * send the person to that interface's sign-in page with their e-mail filled in — instead of the generic
 * "credentials do not match". No session is created here; the 2FA challenge still happens on the right page.
 */
class WithaqLogin extends Login
{
    public function mount(): void
    {
        parent::mount();
        if ($email = request()->query('email')) {
            $this->form->fill(['email' => (string) $email]);
        }
        if (request()->query('moved')) {
            Notification::make()->title('هذه صفحة الدخول الخاصة بحسابك')->body('أدخل كلمة المرور مرة أخرى للمتابعة.')->info()->send();
        }
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            return parent::authenticate();
        } catch (ValidationException $e) {
            if ($target = $this->otherPortalFor($this->data['email'] ?? '', $this->data['password'] ?? '')) {
                $this->redirect($target . '?' . http_build_query(['email' => $this->data['email'], 'moved' => 1]));
                return null;
            }
            throw $e;
        }
    }

    /** Login URL of the account's own interface when the credentials are valid but belong elsewhere. */
    private function otherPortalFor(string $email, string $password): ?string
    {
        if ($email === '' || $password === '') {
            return null;
        }
        $provider = Filament::auth()->getProvider();
        $user = $provider->retrieveByCredentials(['email' => $email, 'password' => $password]);
        if (! $user || ! $provider->validateCredentials($user, ['email' => $email, 'password' => $password])) {
            return null;
        }
        $portal = $user->role?->portal();
        $current = Filament::getCurrentPanel()?->getId();
        if (! $portal || $portal === $current) {
            return null;
        }
        return Filament::getPanel($portal)->getLoginUrl();
    }
}
