<?php

declare(strict_types=1);

namespace GuillaumeTissier\OAuthResourceGuard\Bridge\Symfony;

use GuillaumeTissier\OAuthResourceGuard\Webhook\WebhookEvent;

/**
 * Symfony event dispatched for every verified webhook delivery from the auth server.
 * Listen with #[AsEventListener(event: AuthServerEventReceived::class)].
 */
final readonly class AuthServerEventReceived
{
    public function __construct(public WebhookEvent $event)
    {
    }
}
