<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DomainRepository;
use Doctrine\ORM\Mapping as ORM;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\DomainPath;

/**
 * A node of the domain tree (organisation, dealer group, dealer...). Users belong to one domain and
 * never get access above it or beside it. The materialized path ("/a1/a10/") makes subtree checks a
 * string prefix comparison, in SQL and in resource servers alike.
 */
#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[ORM\Table(name: 'domain')]
#[ORM\Index(name: 'idx_domain_path', columns: ['path'])]
class Domain
{
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_\-]{0,62}$/';
    public const DEFAULT_OWNER_EXPRESSION = '*:m @access:w @domain:w';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 63, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Domain $parent;

    #[ORM\Column(length: 760)]
    private string $path;

    /** The domain's super user: holds $ownerExpression on the domain and all its subdomains. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(length: 1000)]
    private string $ownerExpression = self::DEFAULT_OWNER_EXPRESSION;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $code, string $name, ?self $parent = null)
    {
        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new \InvalidArgumentException(\sprintf('Invalid domain code "%s" (lowercase letters, digits, "-" and "_").', $code));
        }
        $this->code = $code;
        $this->name = $name;
        $this->parent = $parent;
        $this->path = ($parent?->getPath() ?? '/').$code.'/';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDepth(): int
    {
        return substr_count($this->path, '/') - 1;
    }

    /**
     * True when this domain is $ancestor or one of its descendants.
     */
    public function isWithin(self $ancestor): bool
    {
        return DomainPath::isWithin($this->path, $ancestor->getPath());
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): void
    {
        $this->owner = $owner;
    }

    public function getOwnerExpression(): AccessExpression
    {
        return AccessExpression::parse($this->ownerExpression);
    }

    public function setOwnerExpression(AccessExpression $expression): void
    {
        $this->ownerExpression = $expression->toString();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
