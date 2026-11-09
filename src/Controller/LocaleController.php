<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Locales;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Language links ("Français", "Deutsch"...) of every page: the language of this session, back to the page.
 * A signed-in user keeps his own language for his next sessions in My profile.
 */
final class LocaleController extends AbstractController
{
    #[Route('/language/{locale}', name: 'app_language', methods: ['GET'])]
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        if (Locales::isEnabled($locale)) {
            $request->getSession()->set(Locales::SESSION_KEY, $locale);
        }

        return new RedirectResponse(self::safeReturn($request->query->getString('return')));
    }

    /**
     * Only a path of this server ("/login?x=1"), never another site.
     */
    public static function safeReturn(string $path): string
    {
        return 1 === preg_match('#^/(?![/\\\\])#', $path) ? $path : '/';
    }
}
