<?php

declare(strict_types=1);

namespace App\Entity;

use App\I18n\InvalidInput;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A kind of resource protected by the resource servers ("vehicle", "invoice"...). Declaring them lets
 * the server reject typos in access expressions and list them in the rights preview.
 * A type may have facets (ResourceFacet), parts of it granted on their own ("vehicle.pricing").
 * Reserved "@" types (@access, @impersonate, @domain) are built in, never stored here, and have no facets.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resource_type')]
class ResourceType
{
    /** No dot: it separates a type from its facet in access expressions. */
    public const CODE_PATTERN = '/^[a-z][a-z0-9_\-]{0,62}$/';

    /** System powers, never covered by the "*" wildcard. */
    public const RESERVED = [
        '@access' => 'Users\' rights in the domain: r = view, w = edit (never beyond one\'s own rights)',
        '@domain' => 'Subdomains: r = view, w = create subdomains, set their owner and owner rights',
        '@impersonate' => 'Impersonate users of the domain: r = read-only, w = full rights',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 63)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $label;

    /** @var Collection<int, ResourceFacet> */
    #[ORM\OneToMany(targetEntity: ResourceFacet::class, mappedBy: 'type')]
    #[ORM\OrderBy(['code' => 'ASC'])]
    private Collection $facets;

    public function __construct(string $code, string $label)
    {
        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new InvalidInput('access.catalog.invalid_resource_type_code_lowercase', ['{code}' => $code]);
        }
        $this->code = $code;
        $this->label = $label;
        $this->facets = new ArrayCollection();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * @return list<ResourceFacet>
     */
    public function getFacets(): array
    {
        $facets = array_values($this->facets->toArray());
        usort($facets, static fn (ResourceFacet $a, ResourceFacet $b): int => $a->getCode() <=> $b->getCode());

        return $facets;
    }

    /**
     * Called by the ResourceFacet constructor; keeps the collection in sync before any flush.
     */
    public function addFacet(ResourceFacet $facet): void
    {
        if (!$this->facets->contains($facet)) {
            $this->facets->add($facet);
        }
    }

    public function removeFacet(ResourceFacet $facet): void
    {
        $this->facets->removeElement($facet);
    }
}
