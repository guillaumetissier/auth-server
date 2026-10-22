<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Links an authorization code or a refresh token to the impersonation session it was issued under,
 * so that redeeming it later re-checks the session (still active?) and re-applies its rules.
 */
#[ORM\Entity]
#[ORM\Table(name: 'impersonation_link')]
class ImpersonationLink
{
    public const AUTH_CODE = 'auth_code';
    public const REFRESH_TOKEN = 'refresh_token';

    #[ORM\Id]
    #[ORM\Column(name: 'token_id', length: 80)]
    private string $tokenId;

    #[ORM\Column(name: 'token_type', length: 16)]
    private string $tokenType;

    #[ORM\ManyToOne(targetEntity: ImpersonationSession::class)]
    #[ORM\JoinColumn(name: 'session_id', nullable: false, onDelete: 'CASCADE')]
    private ImpersonationSession $session;

    public function __construct(string $tokenId, string $tokenType, ImpersonationSession $session)
    {
        $this->tokenId = $tokenId;
        $this->tokenType = $tokenType;
        $this->session = $session;
    }

    public function getSession(): ImpersonationSession
    {
        return $this->session;
    }
}
