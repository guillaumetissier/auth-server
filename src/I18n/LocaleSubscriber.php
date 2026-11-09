<?php

declare(strict_types=1);

namespace App\I18n;

use App\Entity\User;
use App\Settings\SystemSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Chooses the language of each request (Locales): the session's, else the browser's, else the default one.
 * At sign-in, the user's own language goes into the session.
 */
final readonly class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private SystemSettings $settings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the session (128), before Symfony's LocaleListener (16) and the firewall (8).
            KernelEvents::REQUEST => ['onRequest', 20],
            LoginSuccessEvent::class => 'onLogin',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $chosen = $request->hasPreviousSession() ? $request->getSession()->get(Locales::SESSION_KEY) : null;
        $locale = \is_string($chosen) && Locales::isEnabled($chosen) ? $chosen : (Locales::fromBrowser($request) ?? $this->settings->defaultLocale());
        $request->setLocale($locale);
        $request->setDefaultLocale($locale);
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User && Locales::isEnabled($user->getLocale())) {
            $event->getRequest()->getSession()->set(Locales::SESSION_KEY, $user->getLocale());
            $event->getRequest()->setLocale((string) $user->getLocale());
        }
    }
}
