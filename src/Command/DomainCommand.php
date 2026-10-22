<?php

declare(strict_types=1);

namespace App\Command;

use App\Access\AccessManager;
use App\Access\AccessPolicy;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\InvalidAccessExpression;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:domain', description: 'Lists the domain tree, creates a domain, sets its owner (super user)')]
final readonly class DomainCommand
{
    public function __construct(
        private DomainRepository $domains,
        private UserRepository $users,
        private AccessManager $manager,
        private AccessPolicy $policy,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Domain code (empty: list the tree)')] ?string $code = null,
        #[Option('Create the domain with this name')] ?string $create = null,
        #[Option('Parent domain code, with --create')] ?string $parent = null,
        #[Option('Username of the owner ("none" to remove)')] ?string $owner = null,
        #[Option('Owner rights, e.g. "*:m @access:w @domain:w @impersonate:r"')] ?string $ownerRights = null,
    ): int {
        if (null === $code) {
            $rows = array_map(static fn (Domain $d): array => [
                str_repeat('  ', $d->getDepth() - 1).$d->getCode(),
                $d->getName(),
                $d->getOwner()?->getUsername() ?? '-',
                $d->getOwnerExpression()->toString(),
            ], $this->domains->subtree());
            $io->table(['Domain', 'Name', 'Owner', 'Owner rights'], $rows);

            return Command::SUCCESS;
        }

        $domain = $this->domains->findByCode($code);
        if (null !== $create) {
            if (null !== $domain) {
                $io->error(\sprintf('Domain "%s" already exists.', $code));

                return Command::FAILURE;
            }
            $parentDomain = null !== $parent ? $this->domains->findByCode($parent) : null;
            if (null !== $parent && null === $parentDomain) {
                $io->error(\sprintf('Unknown parent domain "%s".', $parent));

                return Command::FAILURE;
            }
            try {
                $domain = $this->manager->createDomain($code, $create, $parentDomain);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }
            $io->success(\sprintf('Domain %s created.', $domain->getPath()));
        }
        if (null === $domain) {
            $io->error(\sprintf('Unknown domain "%s".', $code));

            return Command::FAILURE;
        }

        if (null !== $owner || null !== $ownerRights) {
            $ownerUser = $domain->getOwner();
            if (null !== $owner) {
                $ownerUser = 'none' === $owner ? null : $this->users->findOneByUsername($owner);
                if ('none' !== $owner && null === $ownerUser) {
                    $io->error(\sprintf('Unknown user "%s".', $owner));

                    return Command::FAILURE;
                }
            }
            try {
                $expression = null !== $ownerRights ? AccessExpression::parse($ownerRights) : $domain->getOwnerExpression();
            } catch (InvalidAccessExpression $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }
            $denial = $this->ownerDenial($domain, $ownerUser, $expression);
            if (null !== $denial) {
                $io->error($denial);

                return Command::FAILURE;
            }
            $this->manager->setOwner($domain, $ownerUser, $expression, null);
            $io->success(\sprintf('Domain %s: owner %s, owner rights "%s".', $domain->getPath(), $ownerUser?->getUsername() ?? 'none', $expression->toString()));
        }

        return Command::SUCCESS;
    }

    private function ownerDenial(Domain $domain, ?object $owner, AccessExpression $expression): ?string
    {
        if (null !== ($error = $this->policy->unknownTypes($expression))) {
            return $error;
        }
        $ownerDomain = $owner?->getDomain();
        if (null !== $ownerDomain && !$domain->isWithin($ownerDomain)) {
            return 'The owner must belong to this domain or to a domain above it.';
        }

        return null;
    }
}
