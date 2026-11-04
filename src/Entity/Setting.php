<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A system setting changed in the administration pages. It takes precedence over the environment
 * variable of the same meaning (see App\Settings\SystemSettings); without a row, the variable applies.
 */
#[ORM\Entity]
#[ORM\Table(name: 'system_setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(name: 'name', length: 64)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $value;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $updatedBy;

    public function __construct(string $name, ?string $value, ?User $updatedBy, \DateTimeImmutable $now)
    {
        $this->name = $name;
        $this->value = $value;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = $now;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function change(?string $value, ?User $updatedBy, \DateTimeImmutable $now): void
    {
        $this->value = $value;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = $now;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }
}
