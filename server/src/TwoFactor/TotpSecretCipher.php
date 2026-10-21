<?php

declare(strict_types=1);

namespace App\TwoFactor;

/**
 * Encrypts TOTP secrets at rest (libsodium secretbox: XSalsa20-Poly1305).
 * A database dump alone is then not enough to generate valid 2FA codes.
 */
final readonly class TotpSecretCipher
{
    private string $key;

    public function __construct(#[\SensitiveParameter] string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException('TOTP_ENCRYPTION_KEY must be the base64 encoding of exactly 32 random bytes.');
        }
        $this->key = $key;
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        if (!str_starts_with($encrypted, 'v1:')) {
            throw new \UnexpectedValueException('Unknown TOTP secret format.');
        }

        $raw = base64_decode(substr($encrypted, 3), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \UnexpectedValueException('Corrupted TOTP secret.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if (false === $plain) {
            throw new \UnexpectedValueException('Unable to decrypt the TOTP secret (wrong TOTP_ENCRYPTION_KEY?).');
        }

        return $plain;
    }
}
