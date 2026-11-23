<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use App\Authorization\Role;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    /** @use HasFactory<UserFactory> */
    use Archivable, Auditable, HasFactory, Notifiable;

    protected $fillable = ['subscriber_id', 'name', 'name_en', 'email', 'phone', 'role', 'password'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'archived_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(UserScope::class);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === Role::PlatformAdmin;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->role->can($permission);
    }

    /** One app, one interface per role (REQ-0271, REQ-0494): a user enters only their role's panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->archived_at === null && $panel->getId() === $this->role->portal() && ! $this->subscriberSuspended();
    }

    /** A suspended subscription stops every user of the office — Filament checks this on every request, not only at sign-in. */
    public function subscriberSuspended(): bool
    {
        if ($this->subscriber_id === null) {
            return false;
        }
        return \Illuminate\Support\Facades\DB::table('subscribers')->where('id', $this->subscriber_id)->value('status') === 'suspended';
    }

    public function getFilamentName(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}
