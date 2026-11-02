<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\AccessGrant;
use App\Entity\Domain;
use App\Entity\ImpersonationLink;
use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Event\AccountEvent;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Changes to accounts: creation, identity, password, status, deletion. Callers check the permissions
 * first (AccessPolicy); every change is announced as an AccountEvent (audit log, webhooks).
 */
final readonly class AccountManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @return string|null why this username or e-mail cannot be used by $user (null: a new user)
     */
    public function identityProblem(string $username, ?string $email, ?User $user = null): ?string
    {
        if ('' === $username || mb_strlen($username) > 180 || 1 !== preg_match('/^[\p{L}\p{N}._@+-]+$/u', $username)) {
            return 'The username may only contain letters, digits and . _ @ + - (no spaces).';
        }
        $sameUsername = $this->users->findOneByUsername($username);
        if (null !== $sameUsername && $sameUsername !== $user) {
            return \sprintf('The username "%s" is already taken.', $username);
        }
        if (null !== $email) {
            if (mb_strlen($email) > 180 || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                return \sprintf('"%s" is not a valid e-mail address.', $email);
            }
            $sameEmail = $this->users->findOneBy(['email' => $email]);
            if (null !== $sameEmail && $sameEmail !== $user) {
                return \sprintf('The e-mail address %s is already used by another account.', $email);
            }
        }

        return null;
    }

    /**
     * New account without a usable password: the user chooses it through an invitation link.
     */
    public function create(string $username, string $externalUserId, ?string $email, ?Domain $domain, ?User $actor): User
    {
        if (null !== $this->users->findOneBy(['externalUserId' => $externalUserId])) {
            throw new \InvalidArgumentException(\sprintf('The external user id "%s" is already linked to another account.', $externalUserId));
        }
        $user = new User($username, $externalUserId);
        $user->setEmail($email);
        $user->setDomain($domain);
        // Random password nobody knows, replaced through the invitation link.
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(32))));
        $this->em->persist($user);
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_CREATED, $user, [
            'domain' => $domain?->getPath(),
            'actor_id' => $actor?->getExternalUserId(),
        ]));

        return $user;
    }

    public function updateIdentity(User $user, string $username, ?string $email, ?User $actor): void
    {
        $changes = [];
        if ($user->getUsername() !== $username) {
            $changes[] = 'username';
            $user->setUsername($username);
        }
        if ($user->getEmail() !== $email) {
            $changes[] = 'email';
            $user->setEmail($email);
        }
        if ([] === $changes) {
            return;
        }
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(AccountEvent::USER_UPDATED, $user, [
            'changed' => implode(',', $changes),
            'actor_id' => $actor?->getExternalUserId(),
        ]));
    }

    /**
     * Sets the password, consumes any password link and unlocks the account. Other sessions of the
     * user end (the stored hash changes) and his refresh tokens stop working.
     */
    public function changePassword(User $user, string $plainPassword, ?User $actor = null): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->clearPasswordLink();
        $user->unlock();
        $this->em->flush();
        $this->revokeTokens($user);

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_PASSWORD_CHANGED, $user, [
            'actor_id' => $actor?->getExternalUserId(),
        ]));
    }

    /**
     * Disabled: no sign-in, sessions end at their next request, refresh tokens revoked.
     * Access tokens already issued stay valid until they expire (OAUTH_ACCESS_TOKEN_TTL).
     */
    public function setEnabled(User $user, bool $enabled, ?User $actor): void
    {
        if ($user->isEnabled() === $enabled) {
            return;
        }
        $user->setEnabled($enabled);
        if ($enabled) {
            $user->unlock();
        }
        $this->em->flush();
        if (!$enabled) {
            $this->revokeTokens($user);
        }

        $this->dispatcher->dispatch(new AccountEvent($enabled ? AccountEvent::USER_ENABLED : AccountEvent::USER_DISABLED, $user, [
            'actor_id' => $actor?->getExternalUserId(),
        ]));
    }

    /**
     * Removes 2FA (lost phone): the user sets it up again at his next sign-in, as the policy requires.
     */
    public function resetTwoFactor(User $user, ?User $actor): void
    {
        $user->disableTotp();
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_TWO_FACTOR_DISABLED, $user, [
            'actor_id' => $actor?->getExternalUserId(),
        ]));
    }

    /**
     * Deletes the account. Its rights and impersonation history go with it (foreign keys), domains it
     * owned lose their owner, its tokens are revoked.
     */
    public function delete(User $user, ?User $actor): void
    {
        $this->revokeTokens($user);
        // Announced before the removal: listeners still read the user.
        $this->dispatcher->dispatch(new AccountEvent(AccountEvent::USER_DELETED, $user, [
            'actor_id' => $actor?->getExternalUserId(),
        ]));
        // What the foreign keys do on MySQL, done here so that it holds on every database.
        $this->em->createQuery('DELETE FROM '.AccessGrant::class.' g WHERE g.user = :u')->setParameter('u', $user)->execute();
        $this->em->createQuery('UPDATE '.AccessGrant::class.' g SET g.grantedBy = NULL WHERE g.grantedBy = :u')->setParameter('u', $user)->execute();
        $this->em->createQuery('UPDATE '.Domain::class.' d SET d.owner = NULL WHERE d.owner = :u')->setParameter('u', $user)->execute();
        $this->em->createQuery('DELETE FROM '.ImpersonationLink::class.' l WHERE l.session IN (SELECT s.id FROM '.ImpersonationSession::class.' s WHERE s.admin = :u OR s.target = :u)')->setParameter('u', $user)->execute();
        $this->em->createQuery('DELETE FROM '.ImpersonationSession::class.' s WHERE s.admin = :u OR s.target = :u')->setParameter('u', $user)->execute();
        $this->em->remove($user);
        $this->em->flush();
    }

    /**
     * Revokes the access and refresh tokens of $user (their "user identifier" is his external id).
     */
    public function revokeTokens(User $user): void
    {
        $id = $user->getExternalUserId();
        $this->em->createQueryBuilder()->update(RefreshToken::class, 'r')->set('r.revoked', ':yes')
            ->where('IDENTITY(r.accessToken) IN (SELECT a.identifier FROM '.AccessToken::class.' a WHERE a.userIdentifier = :id)')
            ->setParameter('yes', true)->setParameter('id', $id)
            ->getQuery()->execute();
        $this->em->createQueryBuilder()->update(AccessToken::class, 'a')->set('a.revoked', ':yes')
            ->where('a.userIdentifier = :id')
            ->setParameter('yes', true)->setParameter('id', $id)
            ->getQuery()->execute();
    }
}
