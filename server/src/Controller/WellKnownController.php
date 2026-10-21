<?php

declare(strict_types=1);

namespace App\Controller;

use GuillaumeTissier\OAuthResourceGuard\Dpop\DpopProofValidator;
use Jose\Component\KeyManagement\JWKFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Discovery endpoints used by clients and resource servers.
 */
#[AsController]
final readonly class WellKnownController
{
    public function __construct(
        private string $issuer,
        private string $publicKeyPath,
    ) {
    }

    /**
     * Public key(s) verifying access token signatures (RFC 7517).
     */
    #[Route('/.well-known/jwks.json', name: 'well_known_jwks', methods: ['GET'])]
    public function jwks(): JsonResponse
    {
        $jwk = JWKFactory::createFromKeyFile($this->publicKeyPath, null, ['use' => 'sig', 'alg' => 'RS256']);
        $values = $jwk->toPublic()->all();
        $values['kid'] = $jwk->thumbprint('sha256');

        return $this->cacheable(['keys' => [$values]], 3600);
    }

    /**
     * Authorization server metadata (RFC 8414).
     */
    #[Route('/.well-known/oauth-authorization-server', name: 'well_known_metadata', methods: ['GET'])]
    public function metadata(): JsonResponse
    {
        $issuer = rtrim($this->issuer, '/');

        return $this->cacheable([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/authorize',
            'token_endpoint' => $issuer.'/token',
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'dpop_signing_alg_values_supported' => DpopProofValidator::SUPPORTED_ALGORITHMS,
            'scopes_supported' => ['profile', 'offline_access'],
        ], 3600);
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }

    private function cacheable(array $data, int $maxAge): JsonResponse
    {
        $response = new JsonResponse($data, 200, ['Access-Control-Allow-Origin' => '*']);
        $response->setPublic();
        $response->setMaxAge($maxAge);

        return $response;
    }
}
