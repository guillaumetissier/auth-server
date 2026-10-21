<?php

declare(strict_types=1);

namespace App\Dpop;

use League\Bundle\OAuth2ServerBundle\Event\TokenRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Sets "token_type": "DPoP" on token responses for DPoP-bound tokens (RFC 9449 section 5),
 * league/oauth2-server always answers "Bearer".
 */
#[AsEventListener(event: OAuth2Events::TOKEN_REQUEST_RESOLVE)]
final readonly class TokenResponseListener
{
    public function __construct(private DpopContext $context)
    {
    }

    public function __invoke(TokenRequestResolveEvent $event): void
    {
        $response = $event->getResponse();
        if (null === $this->context->jkt() || 200 !== $response->getStatusCode()) {
            return;
        }

        $body = json_decode((string) $response->getContent(), true);
        if (!\is_array($body) || !isset($body['access_token'])) {
            return;
        }

        $body['token_type'] = 'DPoP';
        $response->setContent((string) json_encode($body, \JSON_UNESCAPED_SLASHES));
    }
}
