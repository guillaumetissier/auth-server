<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * A part of a resource type ("pricing" of "vehicle"), granted on its own in access expressions as
 * "vehicle.pricing:w". The auth server only knows its name: each resource server decides which of its
 * fields belong to it. The description tells administrators what it covers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resource_facet')]
class ResourceFacet
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_\-]{0,62}$/';

    /** Full code, "vehicle.pricing". */
    #[ORM\Id]
    #[ORM\Column(length: 127)]
    private string $code;

    #[ORM\ManyToOne(inversedBy: 'facets')]
    #[ORM\JoinColumn(name: 'type_code', referencedColumnName: 'code', nullable: false, onDelete: 'RESTRICT')]
    private ResourceType $type;

    #[ORM\Column(length: 63)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description;

    public function __construct(ResourceType $type, string $name, string $label, ?string $description = null)
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid facet "%s" (lowercase letters, digits, "-" and "_", starting with a letter).', $name));
        }
        $this->type = $type;
        $this->name = $name;
        $this->code = $type->getCode().AccessExpression::FACET_SEPARATOR.$name;
        $this->label = $label;
        $this->description = self::clean($description);
        $type->addFacet($this);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getType(): ResourceType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return bool true when something changed
     */
    public function describe(string $label, ?string $description): bool
    {
        $description = self::clean($description);
        if ($label === $this->label && $description === $this->description) {
            return false;
        }
        $this->label = $label;
        $this->description = $description;

        return true;
    }

    private static function clean(?string $description): ?string
    {
        $description = null === $description ? '' : trim($description);

        return '' === $description ? null : mb_substr($description, 0, 255);
    }
}
