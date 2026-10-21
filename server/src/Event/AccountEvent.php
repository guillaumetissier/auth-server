<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use GuillaumeTissier\OAuthResourceGuard\Webhook\WebhookEvent;

/**
 * Domain event about an account, dispatched on the Symfony event dispatcher.
 *
 * This is the internal extension point: add a listener for AccountEvent to react inside the
 * auth server. External applications receive the same events as signed webhooks (WebhookNotifier).
 *
 * @see WebhookEvent for the list of types
 */
final readonly class AccountEvent
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public string $type,
        public User $user,
        public array $context = [],
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }
}
