<?php

declare(strict_types=1);

namespace App\Access;

use App\Repository\UserRepository;
use League\Bundle\OAuth2ServerBundle\Event\AccessTokenExtraClaimsResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Adds the user's rights to every access token:
 *   "dom": "/a1/a10/"                                    (only for users attached to a domain)
 *   "acl": [["/a1/a10/", true, "*:w tire:r"], ...]       (path, includes subdomains, expression)
 * During an impersonation the subject is the impersonated user: the token carries his rights
 * (resource servers cap them at read for read-only impersonations).
 */
#[AsEventListener(event: OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE)]
final readonly class AccessClaimsListener
{
    public function __construct(
        private UserRepository $users,
        private AccessResolver $resolver,
    ) {
    }

    public function __invoke(AccessTokenExtraClaimsResolveEvent $event): void
    {
        $subject = $event->getUserIdentifier();
        $user = null !== $subject ? $this->users->findOneBy(['externalUserId' => $subject]) : null;
        if (null === $user) {
            return; // client credentials grant, or unknown subject
        }

        $acl = $this->resolver->aclFor($user);
        if (null === $acl->userDomain && [] === $acl->entries) {
            return;
        }

        $event->setExtraClaims($acl->toClaims() + $event->getExtraClaims());
    }
}
