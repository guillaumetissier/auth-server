<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;

/**
 * DPoP key (RFC 7638 thumbprint) a refresh token is bound to.
 *
 * Access tokens carry their binding in the "cnf.jkt" claim. Refresh tokens are opaque, so the
 * binding is stored here, keyed by the refresh token itself (not by its access token, which
 * league:oauth2-server:clear-expired-tokens deletes long before the refresh token expires).
 * Rows disappear with their refresh token (ON DELETE CASCADE).
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth2_dpop_binding')]
class DpopBinding
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: RefreshToken::class)]
    #[ORM\JoinColumn(name: 'refresh_token', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
    private RefreshToken $refreshToken;

    #[ORM\Column(length: 64)]
    private string $jkt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(RefreshToken $refreshToken, string $jkt)
    {
        $this->refreshToken = $refreshToken;
        $this->jkt = $jkt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getJkt(): string
    {
        return $this->jkt;
    }
}
