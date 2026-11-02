<?php

declare(strict_types=1);

namespace App\Command;

use App\Account\PasswordRules;
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
        #[Option('E-mail address, where password links are sent')] ?string $email = null,
    ): int {
        $email = null === $email || '' === trim($email) ? null : mb_strtolower(trim($email));
        if (null !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error(\sprintf('"%s" is not a valid e-mail address.', $email));

            return Command::FAILURE;
        }
        if (null !== $email && null !== $this->users->findOneBy(['email' => $email])) {
            $io->error(\sprintf('The e-mail address %s is already used by another account.', $email));

            return Command::FAILURE;
        }
        if (null !== $this->users->findOneByUsername($username)) {
            $io->error(\sprintf('User "%s" already exists.', $username));

            return Command::FAILURE;
        }
        if (null !== $this->users->findOneBy(['externalUserId' => $externalUserId])) {
            $io->error(\sprintf('External user id "%s" is already linked to another account.', $externalUserId));

            return Command::FAILURE;
        }

        $password ??= $io->askHidden('Password');
        if (!\is_string($password) || null !== ($problem = PasswordRules::problem($password))) {
            $io->error($problem ?? 'A password is required.');

            return Command::FAILURE;
        }

        $user = new User($username, $externalUserId);
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_CREATED, $user));

        $io->success(\sprintf('User "%s" created (sub = %s).', $username, $externalUserId));

        return Command::SUCCESS;
    }
}
