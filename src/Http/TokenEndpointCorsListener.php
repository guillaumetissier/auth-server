<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS for the token endpoint, so browser applications (React SPA) on other origins can
 * exchange their authorization code. Only origins listed in CORS_ALLOWED_ORIGINS are allowed;
 * no credentials (cookies) are ever involved on this endpoint.
 */
final readonly class TokenEndpointCorsListener
{
    /** Endpoints browser applications call directly. */
    private const PATHS = ['/token', '/impersonation/end'];

    /** @var list<string> */
    private array $allowedOrigins;

    /**
     * @param array<mixed> $allowedOrigins
     */
    public function __construct(#[Autowire('%env(csv:CORS_ALLOWED_ORIGINS)%')] array $allowedOrigins)
    {
        $this->allowedOrigins = array_values(array_filter(array_map(
            static fn (mixed $o): string => \is_string($o) ? rtrim(trim($o), '/') : '',
            $allowedOrigins,
        )));
    }

    /**
     * Answers preflight requests before routing (the route only accepts POST).
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !\in_array($request->getPathInfo(), self::PATHS, true) || !$request->isMethod('OPTIONS')) {
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        if (null !== $origin = $this->allowedOrigin($request)) {
            $response->headers->add([
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'POST',
                'Access-Control-Allow-Headers' => 'Content-Type, DPoP, Authorization',
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin',
            ]);
        }
        $event->setResponse($response);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !\in_array($request->getPathInfo(), self::PATHS, true) || $request->isMethod('OPTIONS')) {
            return;
        }

        if (null !== $origin = $this->allowedOrigin($request)) {
            $event->getResponse()->headers->add([
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Expose-Headers' => 'DPoP-Nonce, WWW-Authenticate',
                'Vary' => 'Origin',
            ]);
        }
    }

    private function allowedOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');

        return null !== $origin && \in_array($origin, $this->allowedOrigins, true) ? $origin : null;
    }
}
