<?php

declare(strict_types=1);

namespace App\Command;

use App\Access\ProfileManager;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\InvalidAccessExpression;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Access profiles from the console:
 *
 *   app:profile                                               lists the profiles and their use
 *   app:profile pricer                                        shows a profile and who has it
 *   app:profile pricer "Pricer" "vehicle.pricing:w" --description="Changes prices"   creates or changes it
 *   app:profile pricer --remove                               removes a profile nobody has any more
 *
 * Giving a profile to a user: app:access alice a10 --access-profile=pricer --subdomains
 */
#[AsCommand(name: 'app:profile', description: 'Lists, shows, creates, changes or removes access profiles')]
final readonly class ProfileCommand
{
    public function __construct(
        private ProfileManager $profiles,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Profile code ("pricer")')] ?string $code = null,
        #[Argument('Name ("Pricer")')] ?string $name = null,
        #[Argument('Rights, e.g. "vehicle.pricing:w"')] ?string $rights = null,
        #[Option('What the profile is for')] ?string $description = null,
        #[Option('Remove the profile')] bool $remove = false,
    ): int {
        if (null === $code) {
            $usage = $this->profiles->usageCounts();
            $io->table(['Code', 'Name', 'Rights', 'Description', 'Given'], array_map(
                static fn ($p): array => [$p->getCode(), $p->getName(), $p->getExpression()->toString(), $p->getDescription() ?? '', $usage[$p->getCode()] ?? 0],
                $this->profiles->profiles(),
            ));

            return Command::SUCCESS;
        }

        $profile = $this->profiles->find($code);
        try {
            if ($remove) {
                if (null === $profile) {
                    throw new \InvalidArgumentException(\sprintf('Unknown profile "%s".', $code));
                }
                $this->profiles->remove($profile, null);
                $this->em->flush();
                $io->success(\sprintf('Profile "%s" removed.', $profile->getCode()));

                return Command::SUCCESS;
            }
            if (null !== $name || null !== $rights || null !== $description) {
                // Arguments left out keep their current value.
                $profile = $this->profiles->save(
                    $code,
                    $name ?? $profile?->getName() ?? '',
                    null === $rights ? ($profile?->getExpression() ?? AccessExpression::parse('')) : AccessExpression::parse($rights),
                    $description ?? $profile?->getDescription(),
                    null,
                    null === $profile,
                );
                $this->em->flush();
                $io->success(\sprintf('Profile "%s" saved.', $profile->getCode()));
            }
        } catch (\InvalidArgumentException|\DomainException|InvalidAccessExpression $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (null === $profile) {
            $io->error(\sprintf('Unknown profile "%s".', $code));

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('<info>%s</info> (%s): %s', $profile->getName(), $profile->getCode(), $profile->getExpression()->toString()));
        $io->table(['User', 'Domain', 'Subdomains', 'Adjustments', 'Rights'], array_map(
            static fn ($g): array => [
                $g->getUser()->getUsername(),
                $g->getDomain()->getPath(),
                $g->includesSubdomains() ? 'yes' : 'no',
                $g->getAdjustments()->toString(),
                $g->getExpression()->toString(),
            ],
            $this->profiles->grantsOf($profile),
        ));

        return Command::SUCCESS;
    }
}
