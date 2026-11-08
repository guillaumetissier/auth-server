<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\AccessGrant;
use App\Entity\AccessProfile;
use App\Entity\User;
use App\Event\AccountEvent;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Access profiles: named sets of rights given to users on a domain. Creating, changing and removing them is
 * for the super-administrator (checked by the callers); giving one to a user follows the usual rules of
 * AccessPolicy, on the rights it results in.
 *
 * A change applies to every grant linked to the profile, from the users' next token: impact() shows it
 * before, save() writes it to the audit log and notifies a "user.access_changed" webhook per user affected.
 */
final readonly class ProfileManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private ResourceCatalog $catalog,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $auditLogger,
    ) {
    }

    /**
     * @return list<AccessProfile>
     */
    public function profiles(): array
    {
        return $this->em->getRepository(AccessProfile::class)->findBy([], ['name' => 'ASC']);
    }

    public function find(string $code): ?AccessProfile
    {
        return $this->em->find(AccessProfile::class, strtolower(trim($code)));
    }

    /**
     * @return list<AccessGrant> the grants linked to the profile, by domain then user
     */
    public function grantsOf(AccessProfile $profile): array
    {
        return $this->em->createQuery('SELECT g, u, d FROM '.AccessGrant::class.' g JOIN g.user u JOIN g.domain d WHERE g.profile = :profile ORDER BY d.path, u.username')
            ->setParameter('profile', $profile)
            ->getResult();
    }

    /**
     * @return array<string, int> number of grants per profile code
     */
    public function usageCounts(): array
    {
        $counts = [];
        foreach ($this->em->createQuery('SELECT g, p FROM '.AccessGrant::class.' g JOIN g.profile p')->getResult() as $grant) {
            $code = $grant->getProfile()?->getCode();
            if (null !== $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * What a new expression would change: the grants whose rights would differ, with their rights before and after.
     *
     * @return list<array{grant: AccessGrant, before: AccessExpression, after: AccessExpression}>
     */
    public function impact(AccessProfile $profile, AccessExpression $expression): array
    {
        $changes = [];
        foreach ($this->grantsOf($profile) as $grant) {
            $before = $grant->getExpression();
            $after = AccessProfile::combine($expression, $grant->getAdjustments());
            if ($before->toString() !== $after->toString()) {
                $changes[] = ['grant' => $grant, 'before' => $before, 'after' => $after];
            }
        }

        return $changes;
    }

    /**
     * Creates a profile, or changes an existing one ($create false). Does not flush.
     *
     * @throws \InvalidArgumentException invalid code, name or rights, or a code already taken / unknown
     */
    public function save(string $code, string $name, AccessExpression $expression, ?string $description, ?User $actor, bool $create): AccessProfile
    {
        $code = strtolower(trim($code));
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Give the profile a name.');
        }
        if ($expression->isEmpty()) {
            throw new \InvalidArgumentException('Give the profile at least one rule.');
        }
        if (null !== ($error = $this->catalog->unknownTypes($expression))) {
            throw new \InvalidArgumentException($error);
        }

        $profile = $this->find($code);
        if ($create) {
            if (null !== $profile) {
                throw new \InvalidArgumentException(\sprintf('The profile "%s" already exists: edit it instead.', $code));
            }
            $profile = new AccessProfile($code, $name, $expression, $description);
            $this->em->persist($profile);
            $this->audit('access_profile.created', $profile, $actor);

            return $profile;
        }

        if (null === $profile) {
            throw new \InvalidArgumentException(\sprintf('Unknown profile "%s".', $code));
        }
        $impact = $this->impact($profile, $expression);
        if ($profile->update($name, $expression, $description)) {
            $this->audit('access_profile.updated', $profile, $actor, ['grants_changed' => \count($impact)]);
            foreach ($impact as ['grant' => $grant, 'after' => $after]) {
                $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_ACCESS_CHANGED, $grant->getUser(), [
                    'change' => 'profile',
                    'profile' => $profile->getCode(),
                    'domain' => $grant->getDomain()->getCode(),
                    'expression' => $after->toString(),
                    'actor_id' => $actor?->getExternalUserId(),
                    'actor_username' => $actor?->getUsername(),
                ]));
            }
        }

        return $profile;
    }

    /**
     * Removes a profile no grant uses any more. Does not flush.
     *
     * @throws \DomainException still given to some users
     */
    public function remove(AccessProfile $profile, ?User $actor): void
    {
        $grants = $this->grantsOf($profile);
        if ([] !== $grants) {
            $shown = array_map(static fn (AccessGrant $g): string => \sprintf('%s on %s', $g->getUser()->getUsername(), $g->getDomain()->getPath()), \array_slice($grants, 0, 5));
            throw new \DomainException(\sprintf('The profile "%s" is still given to %d user(s): %s%s. Change their rights first.', $profile->getCode(), \count($grants), implode('; ', $shown), \count($grants) > \count($shown) ? '…' : ''));
        }
        $this->em->remove($profile);
        $this->audit('access_profile.removed', $profile, $actor);
    }

    /**
     * @param array<string, int> $context
     */
    private function audit(string $event, AccessProfile $profile, ?User $actor, array $context = []): void
    {
        $this->auditLogger->info($event, [
            'profile' => $profile->getCode(),
            'name' => $profile->getName(),
            'expression' => $profile->getExpression()->toString(),
        ] + $context + [
            'actor' => $actor?->getUsername() ?? '(console)',
            'actor_id' => $actor?->getExternalUserId(),
        ]);
    }
}
