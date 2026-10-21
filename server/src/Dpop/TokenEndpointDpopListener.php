<?php

declare(strict_types=1);

namespace App\Dpop;

use GuillaumeTissier\OAuthResourceGuard\Dpop\DpopException;
use GuillaumeTissier\OAuthResourceGuard\Dpop\DpopProofValidator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Validates the DPoP proof sent to the token endpoint (RFC 9449 section 5) before the
 * OAuth server processes the grant, and remembers the key thumbprint for this request.
 *
 * The "htu" is checked against the public issuer URL (APP_ISSUER), not the URL PHP sees,
 * so the check stays correct behind reverse proxies and inside Docker networks.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)] // after the router (32), before the controller
final readonly class TokenEndpointDpopListener
{
    public function __construct(
        private DpopProofValidator $validator,
        private DpopContext $context,
        private ClientDpopPolicy $policy,
        private string $issuer,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'oauth2_token' !== $request->attributes->get('_route')) {
            return;
        }

        $proofs = $request->headers->all('dpop');

        if ([] === $proofs) {
            if ($this->policy->requiresDpop($this->clientId($request))) {
                $event->setResponse($this->error('A DPoP proof is required for this client.'));
            }

            return;
        }

        if (\count($proofs) > 1) {
            $event->setResponse($this->error('Exactly one DPoP header is allowed.'));

            return;
        }

        try {
            $proof = $this->validator->validate($proofs[0], 'POST', rtrim($this->issuer, '/').'/token');
        } catch (DpopException $e) {
            $event->setResponse($this->error($e->getMessage()));

            return;
        }

        $this->context->bind($proof->jkt);
    }

    private function clientId(Request $request): ?string
    {
        $fromBody = $request->request->get('client_id');
        if (\is_string($fromBody) && '' !== $fromBody) {
            return $fromBody;
        }

        // client_secret_basic: "Authorization: Basic base64(client_id:client_secret)"
        $user = $request->getUser();

        return \is_string($user) && '' !== $user ? urldecode($user) : null;
    }

    private function error(string $description): JsonResponse
    {
        return new JsonResponse(
            ['error' => DpopException::ERROR_CODE, 'error_description' => $description],
            JsonResponse::HTTP_BAD_REQUEST,
            ['Cache-Control' => 'no-store'],
        );
    }
}
