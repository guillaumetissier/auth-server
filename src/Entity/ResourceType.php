<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A kind of resource protected by the resource servers ("vehicle", "invoice"...). Declaring them lets
 * the server reject typos in access expressions and list them in the rights preview.
 * Reserved "@" types (@access, @impersonate, @domain) are built in and never stored here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resource_type')]
class ResourceType
{
    public const CODE_PATTERN = '/^[a-z][a-z0-9_.\-]{0,62}$/';

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

    public function __construct(string $code, string $label)
    {
        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new \InvalidArgumentException(\sprintf('Invalid resource type "%s" (lowercase letters, digits, ".", "-" and "_").', $code));
        }
        $this->code = $code;
        $this->label = $label;
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
}
