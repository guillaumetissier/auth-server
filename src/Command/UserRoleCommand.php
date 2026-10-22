<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Event\AccountEvent;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Grants or removes ROLE_ADMIN / ROLE_SUPER_ADMIN. There is at most one super-administrator:
 * granting the role to someone else requires --transfer, which removes it from the current holder.
 */
#[AsCommand(name: 'app:user:role', description: 'Grants or removes ROLE_ADMIN / ROLE_SUPER_ADMIN')]
final readonly class UserRoleCommand
{
    private const ROLES = [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN];

    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Login name')] string $username,
        #[Argument('ROLE_ADMIN or ROLE_SUPER_ADMIN')] string $role,
        #[Option('Remove the role instead of granting it')] bool $remove = false,
        #[Option('Move ROLE_SUPER_ADMIN from its current holder to this user')] bool $transfer = false,
    ): int {
        $role = strtoupper($role);
        if (!\in_array($role, self::ROLES, true)) {
            $io->error(\sprintf('Unknown role "%s". Allowed: %s.', $role, implode(', ', self::ROLES)));

            return Command::INVALID;
        }

        $user = $this->users->findOneByUsername($username);
        if (null === $user) {
            $io->error(\sprintf('Unknown user "%s".', $username));

            return Command::FAILURE;
        }

        if ($remove) {
            $user->removeRole($role);
            $this->save($user);
            $io->success(\sprintf('%s removed from "%s".', $role, $username));

            return Command::SUCCESS;
        }

        if (User::ROLE_SUPER_ADMIN === $role) {
            $holders = array_filter(
                $this->users->findAll(),
                static fn (User $u): bool => $u->hasRole(User::ROLE_SUPER_ADMIN) && $u->getId() !== $user->getId(),
            );
            if ([] !== $holders && !$transfer) {
                $io->error(\sprintf(
                    'ROLE_SUPER_ADMIN is held by "%s". There can be only one: use --transfer to move it.',
                    implode('", "', array_map(static fn (User $u): string => $u->getUsername(), $holders)),
                ));

                return Command::FAILURE;
            }
            foreach ($holders as $holder) {
                $holder->removeRole(User::ROLE_SUPER_ADMIN);
                $this->save($holder);
                $io->note(\sprintf('ROLE_SUPER_ADMIN removed from "%s".', $holder->getUsername()));
            }
        }

        $user->addRole($role);
        $this->save($user);

        if (!$user->isTotpAuthenticationEnabled()) {
            $io->warning('This user has no two-factor authentication yet: enrollment will be required at the next login.');
        }
        $io->success(\sprintf('%s granted to "%s".', $role, $username));

        return Command::SUCCESS;
    }

    private function save(User $user): void
    {
        $this->em->flush();
        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_ROLES_CHANGED, $user, [
            'roles' => implode(',', $user->getRoles()),
        ]));
    }
}
