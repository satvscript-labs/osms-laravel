<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * A provider-side failure while sending a WhatsApp message. `authError` marks a
 * credential/permission failure (expired token, revoked number) so the caller can
 * flag the store's config for attention and stop retrying.
 */
class WhatsAppException extends RuntimeException
{
    public function __construct(string $message, private bool $authError = false)
    {
        parent::__construct($message);
    }

    public function isAuthError(): bool
    {
        return $this->authError;
    }

    public static function auth(string $message): self
    {
        return new self($message, authError: true);
    }
}
