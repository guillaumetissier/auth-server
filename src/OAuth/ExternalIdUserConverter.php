<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\User;
use League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface;
use League\Bundle\OAuth2ServerBundle\Entity\User as LeagueUser;
use League\OAuth2\Server\Entities\UserEntityInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Makes the access token "sub" claim the user's id in the main application database,
 * so resource servers never need to know about the auth server's own user ids.
 */
final class ExternalIdUserConverter implements UserConverterInterface
{
    public function toLeague(UserInterface $user): UserEntityInterface
    {
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        $leagueUser = new LeagueUser();
        $leagueUser->setIdentifier($user->getExternalUserId());

        return $leagueUser;
    }
}
