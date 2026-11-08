<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * A named set of rights ("Pricer": "vehicle.pricing:w"), given to users on a domain instead of writing
 * the rights each time. Grants stay linked to it: changing a profile changes the rights of every user who
 * has it, from their next token. A grant can adjust its profile (AccessGrant): see combine().
 */
#[ORM\Entity]
#[ORM\Table(name: 'access_profile')]
class AccessProfile
{
    public const CODE_PATTERN = '/^[a-z][a-z0-9_\-]{0,62}$/';

    #[ORM\Id]
    #[ORM\Column(length: 63)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 1000)]
    private string $expression;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, AccessExpression $expression, ?string $description = null)
    {
        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new \InvalidArgumentException(\sprintf('Invalid profile code "%s" (lowercase letters, digits, "-" and "_", starting with a letter).', $code));
        }
        $this->code = $code;
        $this->update($name, $expression, $description);
    }

    /**
     * @return bool true when something changed
     */
    public function update(string $name, AccessExpression $expression, ?string $description): bool
    {
        $description = null === $description || '' === trim($description) ? null : mb_substr(trim($description), 0, 255);
        $changed = !isset($this->name)
            || $name !== $this->name
            || $expression->toString() !== $this->expression
            || $description !== $this->description;
        if ($changed) {
            $this->name = $name;
            $this->expression = $expression->toString();
            $this->description = $description;
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $changed;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getExpression(): AccessExpression
    {
        return AccessExpression::parse($this->expression);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * The rights of a profile adjusted by a grant: each rule of $adjustments replaces the profile's rule for the
     * same type and scope ("invoice:-" replaces "invoice:r", "user:w:own" replaces "user:r:own"), the others
     * are added. As evaluation always prefers the most specific rule, "vehicle.pricing:r" added to "vehicle:w"
     * caps the prices at read and leaves the rest of the vehicle writable.
     */
    public static function combine(AccessExpression $profile, AccessExpression $adjustments): AccessExpression
    {
        $rules = [];
        foreach ([$profile, $adjustments] as $expression) {
            foreach (array_filter(explode(' ', $expression->toString())) as $rule) {
                $parts = explode(':', $rule);
                // Key: type and scope, "invoice" or "user:own".
                $rules[$parts[0].(isset($parts[2]) ? ':'.$parts[2] : '')] = $rule;
            }
        }

        return AccessExpression::parse(implode(' ', $rules));
    }
}
