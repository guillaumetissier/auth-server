<?php

declare(strict_types=1);

namespace App\Impersonation;

use GuillaumeTissier\OAuthResourceGuard\Token\Impersonation;
use League\Bundle\OAuth2ServerBundle\Event\AccessTokenExtraClaimsResolveEvent;
use League\Bundle\OAuth2ServerBundle\Event\ScopeResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Shapes the tokens issued under an impersonation session:
 *  - read-only sessions keep only IMPERSONATION_READONLY_SCOPES;
 *  - claims: "act" (RFC 8693, the administrator), "imp" (session id), "imp_mode".
 */
final readonly class ImpersonationTokenListener
{
    public function __construct(
        private ImpersonationContext $context,
        private ImpersonationPolicy $policy,
    ) {
    }

    #[AsEventListener(event: OAuth2Events::SCOPE_RESOLVE)]
    public function restrictScopes(ScopeResolveEvent $event): void
    {
        $session = $this->context->session();
        if (null === $session || Impersonation::MODE_READ_ONLY !== $session->getMode()) {
            return;
        }

        $allowed = $this->policy->readOnlyScopes();
        $event->setScopes(...array_values(array_filter(
            $event->getScopes(),
            static fn (Scope $scope): bool => \in_array((string) $scope, $allowed, true),
        )));
    }

    #[AsEventListener(event: OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE)]
    public function addClaims(AccessTokenExtraClaimsResolveEvent $event): void
    {
        $session = $this->context->session();
        if (null === $session) {
            return;
        }

        $event->setExtraClaims([
            'act' => ['sub' => $session->getAdmin()->getExternalUserId()],
            'imp' => $session->getId(),
            'imp_mode' => $session->getMode(),
        ] + $event->getExtraClaims());
    }
}
