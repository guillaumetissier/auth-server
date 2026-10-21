<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An administrator acting on behalf of a user, for a limited time and a stated reason.
 * Every token issued under it carries its id ("imp" claim) and dies with it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'impersonation_session')]
#[ORM\Index(name: 'idx_impersonation_expires_at', columns: ['expires_at'])]
class ImpersonationSession
{
    public const END_BY_ADMIN = 'ended_by_admin';
    public const END_BY_COMMAND = 'ended_by_command';
    public const END_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'admin_id', nullable: false, onDelete: 'CASCADE')]
    private User $admin;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'target_id', nullable: false, onDelete: 'CASCADE')]
    private User $target;

    /** Impersonation::MODE_READ_ONLY or Impersonation::MODE_FULL */
    #[ORM\Column(length: 16)]
    private string $mode;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\Column(name: 'started_at')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'ended_at', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'end_reason', length: 32, nullable: true)]
    private ?string $endReason = null;

    public function __construct(User $admin, User $target, string $mode, string $reason, \DateTimeImmutable $now, \DateInterval $ttl)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->admin = $admin;
        $this->target = $target;
        $this->mode = $mode;
        $this->reason = $reason;
        $this->startedAt = $now;
        $this->expiresAt = $now->add($ttl);
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return null === $this->endedAt && $this->expiresAt > $now;
    }

    public function end(\DateTimeImmutable $now, string $reason): void
    {
        if (null === $this->endedAt) {
            $this->endedAt = min($now, $this->expiresAt);
            $this->endReason = $reason;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAdmin(): User
    {
        return $this->admin;
    }

    public function getTarget(): User
    {
        return $this->target;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getEndReason(): ?string
    {
        return $this->endReason;
    }
}
