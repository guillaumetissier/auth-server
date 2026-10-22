<?php

declare(strict_types=1);

namespace App\Command;

use App\Dpop\DoctrineReplayStore;
use App\Impersonation\ImpersonationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Housekeeping, to run periodically (cron) together with league:oauth2-server:clear-expired-tokens.
 */
#[AsCommand(name: 'app:purge', description: 'Removes expired DPoP replay entries, closes expired impersonations')]
final readonly class PurgeCommand
{
    public function __construct(
        private DoctrineReplayStore $replayStore,
        private ImpersonationManager $impersonations,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf(
            '%d expired DPoP replay entries removed, %d expired impersonation sessions closed.',
            $this->replayStore->purgeExpired(),
            $this->impersonations->closeExpired(),
        ));

        return Command::SUCCESS;
    }
}
