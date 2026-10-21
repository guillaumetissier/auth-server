<?php

declare(strict_types=1);

namespace App\Dpop;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped holder of the DPoP key thumbprint validated at the token endpoint,
 * read by the token-issuing hooks (claims, binding persistence, response type).
 */
final class DpopContext implements ResetInterface
{
    private ?string $jkt = null;

    public function bind(string $jkt): void
    {
        $this->jkt = $jkt;
    }

    public function jkt(): ?string
    {
        return $this->jkt;
    }

    public function reset(): void
    {
        $this->jkt = null;
    }
}
