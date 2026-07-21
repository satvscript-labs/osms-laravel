<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * SEC-05 — TOTP two-factor helper.
 *
 * Wraps pragmarx/google2fa so the controller stays thin and the rules live in one
 * place: how secrets are generated, how codes are verified (including a ±1 window
 * for clock drift), and how single-use recovery codes are issued and consumed.
 */
class TwoFactor
{
    public function __construct(private Google2FA $engine) {}

    /** A fresh base32 secret for a new enrolment. */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /**
     * otpauth:// URI for the authenticator app's QR code. The issuer/label is what
     * the user sees in Google Authenticator, so it names the app and the account.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config('app.name', 'OSMS'),
            $user->email,
            $secret,
        );
    }

    /**
     * Verify a 6-digit code. `window: 1` tolerates one 30s step either side, which
     * covers ordinary clock drift between the server and the phone without
     * meaningfully widening the attack surface.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($code === '') {
            return false;
        }

        return (bool) $this->engine->verifyKey($secret, $code, 1);
    }

    /** Eight single-use recovery codes, for a lost/wiped authenticator device. */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(bin2hex(random_bytes(5))))
            ->all();
    }

    /**
     * Consume a recovery code if it matches. Returns the remaining codes, or null
     * when the code was not valid. Comparison is constant-time so a timing side
     * channel can't be used to guess codes.
     */
    public function consumeRecoveryCode(User $user, string $candidate): ?array
    {
        $candidate = strtoupper(trim($candidate));
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $code) {
            if (hash_equals((string) $code, $candidate)) {
                unset($codes[$i]);

                return array_values($codes);
            }
        }

        return null;
    }
}
