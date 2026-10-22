<?php

declare(strict_types=1);

namespace App\Dpop;

use App\Entity\DpopBinding;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken as RefreshTokenModel;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * Decorates the bundle's refresh token repository to enforce RFC 9449 section 5:
 * a refresh token issued under a DPoP proof can only be redeemed with a proof signed by
 * the same key, so a stolen refresh token is useless without the client's private key.
 *
 *  - persistNewRefreshToken(): records the binding when the token request carried a proof;
 *  - isRefreshTokenRevoked(): called by league/oauth2-server while validating the
 *    refresh_token grant, before anything is issued - the right place to check the key.
 */
final readonly class DpopBindingRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        private RefreshTokenRepositoryInterface $inner,
        private DpopContext $context,
        private EntityManagerInterface $em,
    ) {
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return $this->inner->getNewRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->inner->persistNewRefreshToken($refreshTokenEntity);

        $jkt = $this->context->jkt();
        if (null === $jkt) {
            return;
        }

        $this->em->persist(new DpopBinding(
            $this->em->getReference(RefreshTokenModel::class, $refreshTokenEntity->getIdentifier()),
            $jkt,
        ));
        $this->em->flush();
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $this->inner->revokeRefreshToken($tokenId);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        if ($this->inner->isRefreshTokenRevoked($tokenId)) {
            return true;
        }

        $binding = $this->em->find(DpopBinding::class, $tokenId);
        if (null !== $binding) {
            $jkt = $this->context->jkt();
            if (null === $jkt || !hash_equals($binding->getJkt(), $jkt)) {
                throw OAuthServerException::invalidGrant('The refresh token is bound to another DPoP key.');
            }
        }

        return false;
    }
}
