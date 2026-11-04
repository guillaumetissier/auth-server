<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Settings\SystemSettings;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the access and refresh token lifetimes of the system settings to the OAuth server.
 *
 * The bundle passes OAUTH_ACCESS_TOKEN_TTL / OAUTH_REFRESH_TOKEN_TTL to the grants once, when the
 * container is built. Before each request to the token and authorization endpoints, the grants are
 * enabled again with the current settings, so that a change in the Settings page applies at once.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)] // after the router (32), before the controller
final readonly class TokenLifetimeListener
{
    private const ROUTES = ['oauth2_token', 'oauth2_authorize'];

    public function __construct(
        private SystemSettings $settings,
        private AuthorizationServer $server,
        private AuthCodeGrant $authCodeGrant,
        private RefreshTokenGrant $refreshTokenGrant,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !\in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $access = $this->settings->lifetime(SystemSettings::ACCESS_TOKEN_TTL);
        $refresh = $this->settings->lifetime(SystemSettings::REFRESH_TOKEN_TTL);
        foreach ([$this->authCodeGrant, $this->refreshTokenGrant] as $grant) {
            $grant->setRefreshTokenTTL($refresh);
            $this->server->enableGrantType($grant, $access);
        }
    }
}
