<?php

declare(strict_types=1);

namespace App\Impersonation;

use App\Entity\ImpersonationLink;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * Decorates the refresh token repository: a refresh token issued under an impersonation never
 * outlives its session, and stops working as soon as the session ends.
 */
final readonly class ImpersonationRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        private RefreshTokenRepositoryInterface $inner,
        private ImpersonationContext $context,
        private ImpersonationManager $impersonations,
    ) {
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return $this->inner->getNewRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $session = $this->context->session();
        if (null !== $session) {
            $refreshTokenEntity->setExpiryDateTime(
                $this->impersonations->capExpiry($session, $refreshTokenEntity->getExpiryDateTime()),
            );
        }

        $this->inner->persistNewRefreshToken($refreshTokenEntity);

        if (null !== $session) {
            $this->impersonations->link($refreshTokenEntity->getIdentifier(), ImpersonationLink::REFRESH_TOKEN, $session);
        }
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

        if (null !== $session = $this->impersonations->activeSessionFor($tokenId)) {
            $this->context->set($session);
        }

        return false;
    }
}
