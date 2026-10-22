<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\AccessGrant;
use App\Entity\Domain;
use App\Entity\User;
use App\Event\AccountEvent;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Changes domains and rights after AccessPolicy allowed them, and notifies (audit log + webhooks).
 * $actor is null for console commands and imports.
 */
final readonly class AccessManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function findGrant(User $user, Domain $domain): ?AccessGrant
    {
        return $this->em->getRepository(AccessGrant::class)->findOneBy(['user' => $user, 'domain' => $domain]);
    }

    public function grant(User $user, Domain $domain, AccessExpression $expression, bool $includeSubdomains, ?User $actor): AccessGrant
    {
        $grant = $this->findGrant($user, $domain);
        if (null === $grant) {
            $grant = new AccessGrant($user, $domain, $expression, $includeSubdomains, $actor);
            $this->em->persist($grant);
        } else {
            $grant->update($expression, $includeSubdomains, $actor);
        }
        $this->em->flush();

        $this->notify($user, 'grant', $domain, $actor, [
            'expression' => $expression->toString(),
            'include_subdomains' => $includeSubdomains,
        ]);

        return $grant;
    }

    public function revoke(AccessGrant $grant, ?User $actor): void
    {
        $user = $grant->getUser();
        $domain = $grant->getDomain();
        $this->em->remove($grant);
        $this->em->flush();

        $this->notify($user, 'revoke', $domain, $actor);
    }

    /**
     * Moves the user to another domain. His grants outside the new domain's subtree are removed:
     * they could never apply anyway.
     */
    public function moveUser(User $user, ?Domain $domain, ?User $actor): int
    {
        $user->setDomain($domain);
        $removed = 0;
        if (null !== $domain) {
            foreach ($this->em->getRepository(AccessGrant::class)->findBy(['user' => $user]) as $grant) {
                if (!$grant->getDomain()->isWithin($domain)) {
                    $this->em->remove($grant);
                    ++$removed;
                }
            }
        }
        $this->em->flush();

        $this->notify($user, 'domain', $domain, $actor, ['removed_grants' => $removed]);

        return $removed;
    }

    public function createDomain(string $code, string $name, ?Domain $parent): Domain
    {
        $domain = new Domain($code, $name, $parent);
        $this->em->persist($domain);
        $this->em->flush();

        return $domain;
    }

    public function setOwner(Domain $domain, ?User $owner, AccessExpression $ownerExpression, ?User $actor): void
    {
        $previous = $domain->getOwner();
        $domain->setOwner($owner);
        $domain->setOwnerExpression($ownerExpression);
        $this->em->flush();

        foreach (array_filter([$previous, $owner]) as $user) {
            $this->notify($user, 'ownership', $domain, $actor, ['owner_expression' => $ownerExpression->toString()]);
            if ($previous === $owner) {
                break;
            }
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function notify(User $user, string $change, ?Domain $domain, ?User $actor, array $context = []): void
    {
        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_ACCESS_CHANGED, $user, [
            'change' => $change,
            'domain' => $domain?->getCode(),
            'actor_id' => $actor?->getExternalUserId(),
            'actor_username' => $actor?->getUsername(),
        ] + $context));
    }
}
