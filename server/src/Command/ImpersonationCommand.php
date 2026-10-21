<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ImpersonationSession;
use App\Impersonation\ImpersonationManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists impersonation sessions (audit), or ends one immediately.
 */
#[AsCommand(name: 'app:impersonation', description: 'Lists impersonation sessions, or ends one with --end=<id>')]
final readonly class ImpersonationCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImpersonationManager $impersonations,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Number of sessions to list, newest first')] int $limit = 20,
        #[Option('Only sessions still active')] bool $active = false,
        #[Option('Id of a session to end now')] ?string $end = null,
    ): int {
        if (null !== $end) {
            $session = $this->impersonations->find($end);
            if (null === $session) {
                $io->error(\sprintf('Unknown impersonation session "%s".', $end));

                return Command::FAILURE;
            }
            $this->impersonations->end($session, ImpersonationSession::END_BY_COMMAND);
            $io->success('Impersonation ended. Tokens already issued expire within IMPERSONATION_ACCESS_TOKEN_TTL.');

            return Command::SUCCESS;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('s')->from(ImpersonationSession::class, 's')
            ->orderBy('s.startedAt', 'DESC')->setMaxResults($limit);
        if ($active) {
            $qb->where('s.endedAt IS NULL')->andWhere('s.expiresAt > :now')->setParameter('now', $this->clock->now());
        }

        $now = $this->clock->now();
        $io->table(
            ['Id', 'Admin', 'User', 'Mode', 'Started', 'Status', 'Reason'],
            array_map(static fn (ImpersonationSession $s): array => [
                $s->getId(),
                $s->getAdmin()->getUsername(),
                $s->getTarget()->getUsername(),
                $s->getMode(),
                $s->getStartedAt()->format('Y-m-d H:i'),
                $s->isActive($now) ? 'active until '.$s->getExpiresAt()->format('H:i') : ($s->getEndReason() ?? 'expired'),
                mb_strimwidth($s->getReason(), 0, 40, '…'),
            ], $qb->getQuery()->getResult()),
        );

        return Command::SUCCESS;
    }
}
