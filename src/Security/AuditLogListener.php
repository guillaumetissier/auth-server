<?php

declare(strict_types=1);

namespace App\Security;

use App\Event\AccountEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Writes every account event to the "security_audit" log channel.
 */
#[AsEventListener(event: AccountEvent::class, priority: 10)]
final readonly class AuditLogListener
{
    public function __construct(private LoggerInterface $auditLogger)
    {
    }

    public function __invoke(AccountEvent $event): void
    {
        $this->auditLogger->info($event->type, [
            'username' => $event->user->getUsername(),
            'user_id' => $event->user->getExternalUserId(),
        ] + $event->context);
    }
}
