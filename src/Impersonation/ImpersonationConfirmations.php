<?php

declare(strict_types=1);

namespace App\Impersonation;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Remembers, in the admin's HTTP session, which exact /authorize request a confirmed impersonation
 * session belongs to. Single use: a confirmation cannot be replayed for another client or request.
 */
final readonly class ImpersonationConfirmations
{
    private const KEY = 'impersonation_confirmations';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function remember(string $authorizeUri, string $sessionId): void
    {
        $session = $this->requestStack->getSession();
        $map = $session->get(self::KEY, []);
        $map[hash('sha256', $authorizeUri)] = $sessionId;
        $session->set(self::KEY, $map);
    }

    public function consume(string $authorizeUri): ?string
    {
        $session = $this->requestStack->getSession();
        $map = $session->get(self::KEY, []);
        $key = hash('sha256', $authorizeUri);
        $id = $map[$key] ?? null;
        unset($map[$key]);
        $session->set(self::KEY, $map);

        return \is_string($id) ? $id : null;
    }
}
