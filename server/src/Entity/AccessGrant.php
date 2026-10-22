<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * Rights of a user on a domain (and optionally its subdomains), as an access expression:
 * "*:w tire:r invoice:- user:w:own". One grant per user and domain.
 */
#[ORM\Entity]
#[ORM\Table(name: 'access_grant')]
#[ORM\UniqueConstraint(name: 'uniq_access_grant_user_domain', columns: ['user_id', 'domain_id'])]
class AccessGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\Column]
    private bool $includeSubdomains;

    #[ORM\Column(length: 1000)]
    private string $expression;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $grantedBy;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Domain $domain, AccessExpression $expression, bool $includeSubdomains, ?User $grantedBy)
    {
        $this->user = $user;
        $this->domain = $domain;
        $this->update($expression, $includeSubdomains, $grantedBy);
    }

    public function update(AccessExpression $expression, bool $includeSubdomains, ?User $grantedBy): void
    {
        $this->expression = $expression->toString();
        $this->includeSubdomains = $includeSubdomains;
        $this->grantedBy = $grantedBy;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function includesSubdomains(): bool
    {
        return $this->includeSubdomains;
    }

    public function getExpression(): AccessExpression
    {
        return AccessExpression::parse($this->expression);
    }

    public function getGrantedBy(): ?User
    {
        return $this->grantedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
