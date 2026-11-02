<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The demo API has no user table: the token "sub" (the user's id) is the whole user.
 * A real application would load its own user entity here.
 *
 * @implements UserProviderInterface<InMemoryUser>
 */
final class OAuthUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return new InMemoryUser($identifier, null, ['ROLE_USER']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException('Stateless API: users are never refreshed from a session.');
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}
