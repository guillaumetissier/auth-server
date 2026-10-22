<?php

declare(strict_types=1);

namespace App\Impersonation;

use App\Entity\ImpersonationLink;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

/**
 * Decorates the bundle's authorization code repository:
 *  - a code issued under an impersonation is linked to its session;
 *  - when the code is redeemed (league checks isAuthCodeRevoked() before finalizing scopes and
 *    issuing tokens), the session is re-checked and put in the ImpersonationContext.
 */
final readonly class ImpersonationAuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(
        private AuthCodeRepositoryInterface $inner,
        private ImpersonationContext $context,
        private ImpersonationManager $impersonations,
    ) {
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return $this->inner->getNewAuthCode();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->inner->persistNewAuthCode($authCodeEntity);

        if (null !== $session = $this->context->session()) {
            $this->impersonations->link($authCodeEntity->getIdentifier(), ImpersonationLink::AUTH_CODE, $session);
        }
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->inner->revokeAuthCode($codeId);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        if ($this->inner->isAuthCodeRevoked($codeId)) {
            return true;
        }

        if (null !== $session = $this->impersonations->activeSessionFor($codeId)) {
            $this->context->set($session);
        }

        return false;
    }
}
