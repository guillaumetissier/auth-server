<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\TwoFactor\TwoFactorPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Follow-up of the 2FA roll-out: who has set it up, who keeps postponing.
 */
#[AsCommand(name: 'app:2fa:status', description: 'Shows the two-factor roll-out: policy, enrolled and pending accounts')]
final readonly class TwoFactorStatusCommand
{
    public function __construct(
        private UserRepository $users,
        private TwoFactorPolicy $policy,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('List the accounts without two-factor authentication')] bool $pending = false,
    ): int {
        $users = $this->users->findBy([], ['username' => 'ASC']);
        $withoutTotp = array_values(array_filter($users, static fn (User $u): bool => !$u->isTotpAuthenticationEnabled()));
        $total = \count($users);
        $enrolled = $total - \count($withoutTotp);

        $deadline = $this->policy->deadline();
        $io->definitionList(
            ['Mode' => $this->policy->mode()],
            ['Deadline' => null === $deadline ? 'not set' : $deadline->format('Y-m-d').($this->policy->isDeadlinePassed() ? ' (passed: 2FA required)' : \sprintf(' (%d days left)', $this->policy->daysLeft()))],
            ['Accounts' => (string) $total],
            ['With 2FA' => \sprintf('%d (%d %%)', $enrolled, $total > 0 ? (int) round($enrolled * 100 / $total) : 0)],
            ['Without 2FA' => (string) \count($withoutTotp)],
            ['Administrators without 2FA' => (string) \count(array_filter($withoutTotp, static fn (User $u): bool => $u->isAdministrator()))],
        );

        if ($pending && [] !== $withoutTotp) {
            $io->table(
                ['Username', 'User id', 'Postponed', 'Last postponed', 'Last login'],
                array_map(static fn (User $u): array => [
                    $u->getUsername(),
                    $u->getExternalUserId(),
                    $u->getTwoFactorPostponeCount(),
                    $u->getTwoFactorPostponedAt()?->format('Y-m-d H:i') ?? '-',
                    $u->getLastLoginAt()?->format('Y-m-d H:i') ?? 'never',
                ], $withoutTotp),
            );
        }

        return Command::SUCCESS;
    }
}
