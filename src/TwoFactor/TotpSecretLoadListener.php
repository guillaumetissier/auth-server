<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Decrypts the TOTP secret into a transient property each time a User is loaded,
 * so scheb/2fa can read it without the plaintext ever being stored.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: User::class)]
final readonly class TotpSecretLoadListener
{
    public function __construct(private TotpSecretCipher $cipher)
    {
    }

    public function postLoad(User $user): void
    {
        $encrypted = $user->getEncryptedTotpSecret();
        $user->hydratePlainTotpSecret(null !== $encrypted ? $this->cipher->decrypt($encrypted) : null);
    }
}
