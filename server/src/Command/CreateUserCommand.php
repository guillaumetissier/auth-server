<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Event\AccountEvent;
use App\Repository\UserRepository;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(name: 'app:user:create', description: 'Creates a user (credentials only), linked to a user id of the main application')]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Login name')] string $username,
        #[Argument('Id of this user in the main application database (becomes the token "sub")')] string $externalUserId,
        #[Option('Password (prompted when omitted - avoids leaking it in the shell history)')] ?string $password = null,
    ): int {
        if (null !== $this->users->findOneByUsername($username)) {
            $io->error(\sprintf('User "%s" already exists.', $username));

            return Command::FAILURE;
        }
        if (null !== $this->users->findOneBy(['externalUserId' => $externalUserId])) {
            $io->error(\sprintf('External user id "%s" is already linked to another account.', $externalUserId));

            return Command::FAILURE;
        }

        $password ??= $io->askHidden('Password');
        if (!\is_string($password) || mb_strlen($password) < 12) {
            $io->error('The password must be at least 12 characters long.');

            return Command::FAILURE;
        }

        $user = new User($username, $externalUserId);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_CREATED, $user));

        $io->success(\sprintf('User "%s" created (sub = %s).', $username, $externalUserId));

        return Command::SUCCESS;
    }
}
