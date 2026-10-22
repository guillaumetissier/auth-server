<?php

declare(strict_types=1);

namespace App\Impersonation;

use App\Entity\ImpersonationSession;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped: the impersonation session the code/token being issued in this request belongs to.
 * Set at /authorize (confirmed session) or at /token (session linked to the redeemed code or refresh token).
 */
final class ImpersonationContext implements ResetInterface
{
    private ?ImpersonationSession $session = null;

    public function set(ImpersonationSession $session): void
    {
        $this->session = $session;
    }

    public function session(): ?ImpersonationSession
    {
        return $this->session;
    }

    public function reset(): void
    {
        $this->session = null;
    }
}
