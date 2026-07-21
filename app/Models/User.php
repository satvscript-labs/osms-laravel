<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // SEC-05 — never serialise the second factor.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // SEC-05 — encrypted at rest, so a DB leak alone yields no usable factor.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * SEC-05 — 2FA counts only once the user has proved they can generate a valid
     * code. An unconfirmed secret is ignored, so an abandoned setup can never lock
     * someone out of their own account.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! empty($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    /** Whether this account must complete 2FA setup before proceeding. */
    public function mustSetUpTwoFactor(): bool
    {
        return $this->isSuperadmin()
            && (bool) config('security.superadmin_require_2fa')
            && ! $this->hasTwoFactorEnabled();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isSuperadmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function isStoreAdmin(): bool
    {
        return $this->role === 'store_admin';
    }

    public function hasTenant(): bool
    {
        return ! empty($this->tenant_id);
    }
}
