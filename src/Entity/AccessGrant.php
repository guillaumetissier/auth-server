<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * Rights of a user on a domain (and optionally its subdomains), as an access expression:
 * "*:w tire:r invoice:- user:w:own". One grant per user and domain.
 *
 * A grant may be linked to a profile (AccessProfile): its own expression then adjusts the profile's rights
 * (AccessProfile::combine()), and changing the profile changes this grant too. getExpression() returns the
 * resulting rights; getAdjustments() the grant's own rules.
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

    /** Rules of the grant itself: all its rights without a profile, the adjustments with one. May be empty with a profile. */
    #[ORM\Column(length: 1000)]
    private string $expression;

    #[ORM\ManyToOne(targetEntity: AccessProfile::class)]
    #[ORM\JoinColumn(name: 'profile_code', referencedColumnName: 'code', nullable: true, onDelete: 'RESTRICT')]
    private ?AccessProfile $profile = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $grantedBy;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Domain $domain, AccessExpression $expression, bool $includeSubdomains, ?User $grantedBy, ?AccessProfile $profile = null)
    {
        $this->user = $user;
        $this->domain = $domain;
        $this->update($expression, $includeSubdomains, $grantedBy, $profile);
    }

    /**
     * @param AccessExpression $expression the rights, or with a profile the adjustments of its rights
     */
    public function update(AccessExpression $expression, bool $includeSubdomains, ?User $grantedBy, ?AccessProfile $profile = null): void
    {
        $this->expression = $expression->toString();
        $this->profile = $profile;
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

    /**
     * The rights this grant gives: its profile's adjusted by its own rules, or its own rules alone.
     */
    public function getExpression(): AccessExpression
    {
        $own = AccessExpression::parse($this->expression);

        return null === $this->profile ? $own : AccessProfile::combine($this->profile->getExpression(), $own);
    }

    /**
     * The grant's own rules: its rights without a profile, the adjustments of the profile's rights with one.
     */
    public function getAdjustments(): AccessExpression
    {
        return AccessExpression::parse($this->expression);
    }

    public function getProfile(): ?AccessProfile
    {
        return $this->profile;
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
