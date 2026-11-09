<?php

declare(strict_types=1);

namespace App\I18n;

use Symfony\Component\HttpFoundation\Request;

/**
 * Languages of the interface, and how the language of a request is chosen: the one chosen in this session
 * (language links), else the user's own (My profile, set in the session at sign-in), else the browser's,
 * else the default language of the Settings page.
 */
final class Locales
{
    /** Code => name of the language in itself. */
    public const ENABLED = ['en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español'];

    public const SESSION_KEY = '_locale';

    public static function isEnabled(?string $locale): bool
    {
        return null !== $locale && \array_key_exists($locale, self::ENABLED);
    }

    /**
     * The first enabled language of the browser (Accept-Language), "fr" for "fr-CH", or null.
     */
    public static function fromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', str_replace('-', '_', $language))[0]);
            if (self::isEnabled($primary)) {
                return $primary;
            }
        }

        return null;
    }
}
