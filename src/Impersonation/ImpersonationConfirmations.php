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
        $map = $this->map();
        $map[hash('sha256', $authorizeUri)] = $sessionId;
        $session->set(self::KEY, $map);
    }

    public function consume(string $authorizeUri): ?string
    {
        $session = $this->requestStack->getSession();
        $map = $this->map();
        $key = hash('sha256', $authorizeUri);
        $id = $map[$key] ?? null;
        unset($map[$key]);
        $session->set(self::KEY, $map);

        return \is_string($id) ? $id : null;
    }

    /**
     * @return array<string, string> SHA-256 of the authorization URL => impersonation session id
     */
    private function map(): array
    {
        $stored = $this->requestStack->getSession()->get(self::KEY, []);
        $map = [];
        foreach (\is_array($stored) ? $stored : [] as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }
}
