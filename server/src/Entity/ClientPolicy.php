<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\Client;

/**
 * Per-client settings the OAuth bundle has no column for.
 * A client without a row follows the defaults (DPOP_REQUIRED_BY_DEFAULT).
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth2_client_policy')]
class ClientPolicy
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
    private Client $client;

    #[ORM\Column]
    private bool $requireDpop;

    public function __construct(Client $client, bool $requireDpop)
    {
        $this->client = $client;
        $this->requireDpop = $requireDpop;
    }

    public function requiresDpop(): bool
    {
        return $this->requireDpop;
    }

    public function setRequireDpop(bool $requireDpop): void
    {
        $this->requireDpop = $requireDpop;
    }
}
