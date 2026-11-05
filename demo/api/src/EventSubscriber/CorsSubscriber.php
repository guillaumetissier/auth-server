<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lets the SPA (another origin) call the API from the browser.
 *
 * Preflight requests are answered before the security firewall (they carry no token). Every response
 * exposes WWW-Authenticate, so that the SPA can show why a request was refused.
 */
final class CorsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly string $allowedOrigin)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Higher than the firewall (priority 8).
            KernelEvents::REQUEST => ['onRequest', 250],
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'OPTIONS' !== $request->getMethod() || !$request->headers->has('Access-Control-Request-Method')) {
            return;
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        if ($this->isAllowed($request->headers->get('Origin'))) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, DPoP, Content-Type');
            $response->headers->set('Access-Control-Max-Age', '600');
        }
        $event->setResponse($response);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $origin = $event->getRequest()->headers->get('Origin');
        $headers = $event->getResponse()->headers;
        $headers->set('Vary', 'Origin', false);
        if ($this->isAllowed($origin)) {
            $headers->set('Access-Control-Allow-Origin', (string) $origin);
            $headers->set('Access-Control-Expose-Headers', 'WWW-Authenticate');
        }
    }

    private function isAllowed(?string $origin): bool
    {
        return null !== $origin && $origin === $this->allowedOrigin;
    }
}
