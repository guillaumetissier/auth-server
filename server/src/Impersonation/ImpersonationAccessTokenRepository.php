<?php

declare(strict_types=1);

namespace App\Impersonation;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * Decorates the access token repository: during an impersonation, access tokens live at most
 * IMPERSONATION_ACCESS_TOKEN_TTL and never beyond the session, so ending the session takes
 * effect on resource servers within minutes. league sets the expiry before persisting and
 * builds the JWT afterwards, so changing it here changes the token and its "expires_in".
 */
final readonly class ImpersonationAccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private AccessTokenRepositoryInterface $inner,
        private ImpersonationContext $context,
        private ImpersonationManager $impersonations,
        private ImpersonationPolicy $policy,
    ) {
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        return $this->inner->getNewToken($clientEntity, $scopes, $userIdentifier);
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        if (null !== $session = $this->context->session()) {
            $accessTokenEntity->setExpiryDateTime(
                $this->impersonations->capExpiry($session, $accessTokenEntity->getExpiryDateTime(), $this->policy->accessTokenTtl()),
            );
        }

        $this->inner->persistNewAccessToken($accessTokenEntity);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->inner->revokeAccessToken($tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->inner->isAccessTokenRevoked($tokenId);
    }
}
