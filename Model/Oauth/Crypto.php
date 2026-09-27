<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\Oauth;

/**
 * UpturnStudio_Mcp
 */
class Crypto
{
    private const TOKEN_BYTES = 32;

    /**
     * A cryptographically random, base64url-encoded opaque token (256 bits of entropy).
     *
     * @return string
     */
    public function randomToken(): string
    {
        return $this->base64UrlEncode(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * SHA-256 hash of an opaque value, for lookup-by-equality storage.
     *
     * Deliberately not bcrypt/argon2: those are for low-entropy human passwords, are
     * intentionally slow (which would tax every authenticated request), and are salted so
     * they cannot be looked up by equality at all. The inputs here already carry 256 bits of
     * CSPRNG entropy, so a fast deterministic hash is both correct and necessary.
     *
     * @param string $value
     * @return string
     */
    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @param string $binary
     * @return string
     */
    public function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
