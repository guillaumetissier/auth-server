<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;

/**
 * Domain event about an account, dispatched on the Symfony event dispatcher.
 *
 * This is the internal extension point: add a listener for AccountEvent to react inside the
 * auth server. External applications receive the same events as signed webhooks (WebhookNotifier).
 *
 * @see WebhookEvent for the list of types, completed by the constants below
 */
final readonly class AccountEvent
{
    // Types not (yet) listed in WebhookEvent: account administration.
    public const USER_UPDATED = 'user.updated';
    public const USER_DISABLED = 'user.disabled';
    public const USER_ENABLED = 'user.enabled';
    public const USER_DELETED = 'user.deleted';
    public const USER_PASSWORD_LINK_SENT = 'user.password_link_sent';

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
