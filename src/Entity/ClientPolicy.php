<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\Client;

/**
 * Per-client settings the OAuth bundle has no column for.
 * A client without a row follows the defaults (DPOP_REQUIRED_BY_DEFAULT, no impersonation entry point).
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth2_client_policy')]
class ClientPolicy
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
    private Client $client;

    /** Null: DPOP_REQUIRED_BY_DEFAULT. */
    #[ORM\Column(nullable: true)]
    private ?bool $requireDpop;

    /**
     * Where the client application starts an impersonation, "{user}" being replaced by the target's
     * external user id: e.g. "https://app.example.com/impersonate?user={user}". The application then
     * runs the authorization flow with impersonate=<id> (only it holds the PKCE verifier).
     */
    #[ORM\Column(length: 2000, nullable: true)]
    private ?string $impersonationStartUri = null;

    public function __construct(Client $client, ?bool $requireDpop = null)
    {
        $this->client = $client;
        $this->requireDpop = $requireDpop;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function requiresDpop(): ?bool
    {
        return $this->requireDpop;
    }

    public function setRequireDpop(?bool $requireDpop): void
    {
        $this->requireDpop = $requireDpop;
    }

    public function getImpersonationStartUri(): ?string
    {
        return $this->impersonationStartUri;
    }

    public function setImpersonationStartUri(?string $uri): void
    {
        if (null !== $uri && (!str_contains($uri, '{user}') || !\in_array(parse_url($uri, \PHP_URL_SCHEME), ['http', 'https'], true))) {
            throw new \InvalidArgumentException('The impersonation URI must be an http(s) URL containing "{user}".');
        }
        $this->impersonationStartUri = $uri;
    }

    public function impersonationUriFor(User $target): ?string
    {
        return null === $this->impersonationStartUri ? null : str_replace('{user}', rawurlencode($target->getExternalUserId()), $this->impersonationStartUri);
    }
}
