<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * P5 / REQ-7, matrix row 14 — issuing a password on someone's behalf.
 *
 * ONE place, for both doors that need it: provisioning a brand-new store owner,
 * and re-issuing for a locked-out one. That matters because the rules are the
 * same either way and easy to get subtly wrong twice:
 *
 *   • the secret is RETURNED, never stored anywhere but the hash;
 *   • the secret is never written to the audit log, the application log, or a
 *     flash message that survives more than one request;
 *   • re-issuing invalidates "remember me" on the old device, because handing
 *     out a new password while an old browser stays signed in is not a reset.
 *
 * The alphabet excludes the characters people confuse when reading a password
 * down the phone (0/O, 1/l/I). An operator dictating credentials to a shop owner
 * is the actual delivery mechanism here — a password that cannot be transcribed
 * is a support call, and the operator will "fix" it by choosing something weak.
 */
class CredentialIssuer
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzACDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 14;

    /** A fresh secret. Nothing else in the app may generate one. */
    public function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        // random_int, not Str::random — this is a credential, not an identifier.
        return collect(range(1, self::LENGTH))
            ->map(fn () => $alphabet[random_int(0, $max)])
            ->implode('');
    }

    /**
     * Row 14 — re-issue a store user's password and return it exactly once.
     *
     * @return string the plaintext, for immediate display. Do not persist it,
     *                do not log it, do not put it in a queued email body.
     */
    public function reissue(User $user, string $reason): string
    {
        $password = $this->generate();

        $user->forceFill([
            'password' => Hash::make($password),
            // Kills every "remember me" cookie already issued to this account.
            'remember_token' => Str::random(60),
        ])->save();

        AdminAuditLog::record(
            'user.credential_reissued',
            "Re-issued the password for {$user->email}",
            $user->tenant_id,
            // Note what is NOT here: the password, and any hash of it.
            ['user_id' => $user->id, 'email' => $user->email, 'reason' => $reason],
        );

        return $password;
    }
}
