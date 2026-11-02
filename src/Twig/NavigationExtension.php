<?php

declare(strict_types=1);

namespace App\Twig;

use App\Access\AccessPolicy;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the navigation bar shows to the signed-in user.
 */
final class NavigationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly AccessPolicy $policy,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('can_administer', $this->canAdminister(...))];
    }

    public function canAdminister(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->policy->canAdminister($user);
    }
}
