<?php

declare(strict_types=1);

namespace App\Impersonation;

use App\Entity\ImpersonationLink;
use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Event\AccountEvent;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Starts and ends impersonation sessions, and ties codes/tokens to them.
 */
final readonly class ImpersonationManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImpersonationPolicy $policy,
        private EventDispatcherInterface $dispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function start(User $admin, User $target, string $reason): ImpersonationSession
    {
        $denial = $this->policy->denialReason($admin, $target);
        $mode = $this->policy->modeFor($admin, $target);
        if (null !== $denial || null === $mode) {
            throw new \LogicException($denial ?? 'Not allowed to impersonate.');
        }

        $session = new ImpersonationSession($admin, $target, $mode, $reason, $this->clock->now(), $this->policy->sessionTtl());
        $this->em->persist($session);
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::IMPERSONATION_STARTED, $target, $this->eventContext($session)));

        return $session;
    }

    public function end(ImpersonationSession $session, string $reason): void
    {
        if (null !== $session->getEndedAt()) {
            return;
        }

        $session->end($this->clock->now(), $reason);
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::IMPERSONATION_ENDED, $session->getTarget(), [
            'end_reason' => $reason,
        ] + $this->eventContext($session)));
    }

    /**
     * Marks sessions past their expiry as ended (and notifies), for the audit trail.
     */
    public function closeExpired(): int
    {
        $expired = $this->em->createQueryBuilder()
            ->select('s')->from(ImpersonationSession::class, 's')
            ->where('s.endedAt IS NULL')->andWhere('s.expiresAt <= :now')
            ->setParameter('now', $this->clock->now())
            ->getQuery()->getResult();

        foreach ($expired as $session) {
            $this->end($session, ImpersonationSession::END_EXPIRED);
        }

        return \count($expired);
    }

    public function find(string $id): ?ImpersonationSession
    {
        return $this->em->find(ImpersonationSession::class, $id);
    }

    public function link(string $tokenId, string $tokenType, ImpersonationSession $session): void
    {
        $this->em->persist(new ImpersonationLink($tokenId, $tokenType, $session));
        $this->em->flush();
    }

    /**
     * The session a code/refresh token was issued under, or null for a regular token.
     *
     * @throws OAuthServerException when that session has ended or expired
     */
    public function activeSessionFor(string $tokenId): ?ImpersonationSession
    {
        $link = $this->em->find(ImpersonationLink::class, $tokenId);
        if (null === $link) {
            return null;
        }

        $session = $link->getSession();
        if (!$session->isActive($this->clock->now())) {
            throw OAuthServerException::invalidGrant('The impersonation session has ended.');
        }

        return $session;
    }

    /**
     * Expiry of a token issued under the session: never beyond the session itself.
     */
    public function capExpiry(ImpersonationSession $session, \DateTimeImmutable $expiry, ?\DateInterval $maxLifetime = null): \DateTimeImmutable
    {
        if (null !== $maxLifetime) {
            $expiry = min($expiry, $this->clock->now()->add($maxLifetime));
        }

        return min($expiry, $session->getExpiresAt());
    }

    /**
     * @return array<string, string>
     */
    private function eventContext(ImpersonationSession $session): array
    {
        return [
            'impersonation_id' => $session->getId(),
            'actor_id' => $session->getAdmin()->getExternalUserId(),
            'actor_username' => $session->getAdmin()->getUsername(),
            'mode' => $session->getMode(),
            'reason' => $session->getReason(),
            'expires_at' => $session->getExpiresAt()->format(\DATE_ATOM),
        ];
    }
}
