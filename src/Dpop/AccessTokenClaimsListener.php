<?php

declare(strict_types=1);

namespace App\Dpop;

use League\Bundle\OAuth2ServerBundle\Event\AccessTokenExtraClaimsResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Adds the confirmation claim binding the access token to the client's DPoP key:
 *   "cnf": {"jkt": "<base64url SHA-256 thumbprint of the public key>"}  (RFC 9449 section 6.1)
 */
#[AsEventListener(event: OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE)]
final readonly class AccessTokenClaimsListener
{
    public function __construct(private DpopContext $context)
    {
    }

    public function __invoke(AccessTokenExtraClaimsResolveEvent $event): void
    {
        $jkt = $this->context->jkt();
        if (null === $jkt) {
            return;
        }

        $event->setExtraClaims(['cnf' => ['jkt' => $jkt]] + $event->getExtraClaims());
    }
}
