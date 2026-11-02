<?php

declare(strict_types=1);

namespace App\Security;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Logout started by an application (OpenID Connect RP-Initiated Logout, simplified):
 *
 *   GET /logout?client_id=my-spa&post_logout_redirect_uri=https://app.example.com/
 *
 * The server session is closed as usual, then the browser goes back to the application instead of the
 * login page. To prevent open redirects, the address must have the origin (scheme, host, port) of one of
 * the client's registered redirect URIs. Anything else is ignored: the browser lands on the login page.
 */
#[AsEventListener(event: LogoutEvent::class, priority: 128)] // before Symfony's default target (64)
final readonly class LogoutRedirectListener
{
    public function __construct(private ClientManagerInterface $clients)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $query = $event->getRequest()->query;
        $clientId = $query->get('client_id');
        $target = $query->get('post_logout_redirect_uri');
        if (!\is_string($clientId) || !\is_string($target) || '' === $clientId || '' === $target) {
            return;
        }

        $client = $this->clients->find($clientId);
        $origin = self::origin($target);
        if (null === $client || !$client->isActive() || null === $origin) {
            return;
        }

        foreach ($client->getRedirectUris() as $redirectUri) {
            if (self::origin((string) $redirectUri) === $origin) {
                $event->setResponse(new RedirectResponse($target));

                return;
            }
        }
    }

    /** "https://app.example.com:8443", or null for anything that is not an absolute http(s) URL. */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (false === $parts || !\in_array($scheme, ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user'])) {
            return null;
        }
        $port = $parts['port'] ?? ('https' === $scheme ? 443 : 80);

        return \sprintf('%s://%s:%d', $scheme, strtolower($parts['host']), $port);
    }
}
