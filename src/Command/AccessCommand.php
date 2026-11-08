<?php

declare(strict_types=1);

namespace App\Command;

use App\Access\AccessManager;
use App\Access\AccessPolicy;
use App\Access\AccessPreview;
use App\Access\AccessResolver;
use App\Access\ProfileManager;
use App\Entity\AccessGrant;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use GuillaumeTissier\ResourceGuard\Access\InvalidAccessExpression;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows or changes the domain and the rights of a user.
 *
 *   app:access alice                          rights of alice, and what they give on her domain
 *   app:access alice --domain=a10             idem, previewed on a10
 *   app:access alice --move=a10               moves alice to domain a10
 *   app:access alice a100 "*:w tire:r" --subdomains   grants (or replaces) her rights on a100
 *   app:access alice a100 --access-profile=pricer --subdomains    gives her the profile "pricer" on a100
 *   app:access alice a100 "invoice:-" --access-profile=pricer     idem, adjusted: no invoices
 *   app:access alice a100 --revoke
 */
#[AsCommand(name: 'app:access', description: 'Shows or changes the domain and the rights of a user')]
final readonly class AccessCommand
{
    public function __construct(
        private UserRepository $users,
        private DomainRepository $domains,
        private AccessManager $manager,
        private AccessPolicy $policy,
        private AccessResolver $resolver,
        private AccessPreview $preview,
        private ProfileManager $profiles,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Username')] string $username,
        #[Argument('Domain code of the grant')] ?string $domain = null,
        #[Argument('Access expression, e.g. "*:w tire:r user:w:own"; with --profile, its adjustments')] ?string $expression = null,
        #[Option('Access profile to give (its rights, adjusted by the expression if any)', name: 'access-profile')] ?string $profile = null,
        #[Option('The grant extends to all subdomains')] bool $subdomains = false,
        #[Option('Remove the grant')] bool $revoke = false,
        #[Option('Move the user to this domain ("none": no domain)')] ?string $move = null,
        #[Option('Domain to preview the effective rights on (default: the user\'s domain)')] ?string $preview = null,
    ): int {
        $user = $this->users->findOneByUsername($username);
        if (null === $user) {
            $io->error(\sprintf('Unknown user "%s".', $username));

            return Command::FAILURE;
        }

        if (null !== $move) {
            $target = 'none' === $move ? null : $this->domains->findByCode($move);
            if ('none' !== $move && null === $target) {
                $io->error(\sprintf('Unknown domain "%s".', $move));

                return Command::FAILURE;
            }
            $removed = $this->manager->moveUser($user, $target, null);
            $io->success(\sprintf('%s now belongs to %s%s.', $username, $target?->getPath() ?? 'no domain', $removed > 0 ? \sprintf(' (%d grant(s) outside it removed)', $removed) : ''));
        }

        if (null !== $domain) {
            $grantDomain = $this->domains->findByCode($domain);
            if (null === $grantDomain) {
                $io->error(\sprintf('Unknown domain "%s".', $domain));

                return Command::FAILURE;
            }
            if ($revoke) {
                $grant = $this->manager->findGrant($user, $grantDomain);
                if (null !== $grant) {
                    $this->manager->revoke($grant, null);
                }
                $io->success(\sprintf('Rights of %s on %s removed.', $username, $grantDomain->getPath()));
            } else {
                if (null === $expression && null === $profile) {
                    $io->error('Give the access expression, an --access-profile, or --revoke.');

                    return Command::INVALID;
                }
                $grantProfile = null === $profile ? null : $this->profiles->find($profile);
                if (null !== $profile && null === $grantProfile) {
                    $io->error(\sprintf('Unknown profile "%s".', $profile));

                    return Command::FAILURE;
                }
                try {
                    $parsed = AccessExpression::parse($expression ?? '');
                } catch (InvalidAccessExpression $e) {
                    $io->error($e->getMessage());

                    return Command::INVALID;
                }
                $error = $this->policy->unknownTypes($parsed);
                $userDomain = $user->getDomain();
                if (null === $error && null !== $userDomain && !$grantDomain->isWithin($userDomain)) {
                    $error = \sprintf('%s belongs to %s: rights can only be granted on it or its subdomains.', $username, $userDomain->getPath());
                }
                if (null !== $error) {
                    $io->error($error);

                    return Command::FAILURE;
                }
                $grant = $this->manager->grant($user, $grantDomain, $parsed, $subdomains, null, $grantProfile);
                $io->success(\sprintf(
                    '%s: %s"%s" on %s%s.',
                    $username,
                    null === $grantProfile ? '' : \sprintf('profile %s, ', $grantProfile->getCode()),
                    $grant->getExpression()->toString(),
                    $grantDomain->getPath(),
                    $subdomains ? ' and its subdomains' : '',
                ));
            }
        }

        $this->show($io, $user, $preview);

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, \App\Entity\User $user, ?string $previewCode): void
    {
        $io->writeln(\sprintf('<info>%s</info> belongs to %s', $user->getUsername(), $user->getDomain()?->getPath() ?? 'no domain'));
        $io->table(['Domain', 'Subdomains', 'Rights', 'Origin'], array_merge(
            array_map(static fn (AccessGrant $g): array => [
                $g->getDomain()->getPath(),
                $g->includesSubdomains() ? 'yes' : 'no',
                $g->getExpression()->toString(),
                null === $g->getProfile() ? 'grant' : \sprintf('profile %s%s', $g->getProfile()->getCode(), $g->getAdjustments()->isEmpty() ? '' : ', adjusted: '.$g->getAdjustments()->toString()),
            ], $this->resolver->grantsOf($user)),
            array_map(static fn ($d): array => [$d->getPath(), 'yes', $d->getOwnerExpression()->toString(), 'owner'], $this->domains->ownedBy($user)),
            null !== $user->getDomain() && !$this->resolver->defaultExpression()->isEmpty()
                ? [[$user->getDomain()->getPath(), 'yes', $this->resolver->defaultExpression()->toString(), 'default']] : [],
        ));

        $domain = null !== $previewCode ? $this->domains->findByCode($previewCode) : $user->getDomain();
        if (null === $domain) {
            return;
        }
        $io->writeln(\sprintf('Effective rights on <info>%s</info>:', $domain->getPath()));
        $io->table(['Type', 'Level', 'Own resources'], array_map(
            static fn (array $r): array => [$r['type'], $r['level']->label(), $r['own'] === $r['level'] ? '' : $r['own']->label()],
            array_filter($this->preview->rows($user, $domain), static fn (array $r): bool => AccessLevel::None !== $r['own']),
        ));
    }
}
