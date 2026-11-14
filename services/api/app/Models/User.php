<?php

namespace App\Models;

use App\Notifications\VerifyAccountEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'roles'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function permissions(): array
    {
        $permissions = [];
        foreach ($this->roles ?? [] as $role) {
            $permissions = array_merge($permissions, config('kora.roles.'.$role, []));
        }

        return array_values(array_unique($permissions));
    }

    public function isStaff(): bool
    {
        return count(array_diff($this->roles ?? [], ['client', 'supplier', 'courier'])) > 0;
    }

    public function publicProfile(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'verification_delivery_enabled' => (bool) config('kora.verification_mail_enabled'),
            'locale' => $this->locale,
            'theme' => $this->theme,
            'permissions' => $this->permissions(),
            'staff' => $this->isStaff(),
            'mfa_enabled' => $this->two_factor_confirmed_at !== null,
        ];
    }

    public function sendEmailVerificationNotification(): void
    {
        if (config('kora.verification_mail_enabled')) {
            $this->notify(new VerifyAccountEmail);
        }
    }
}
