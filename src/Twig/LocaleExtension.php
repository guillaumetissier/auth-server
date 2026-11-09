<?php

declare(strict_types=1);

namespace App\Twig;

use App\I18n\Locales;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Dates in the format of the current language, and the list of languages for the language links.
 */
final class LocaleExtension extends AbstractExtension
{
    /** Date formats per language (PHP date() syntax). */
    private const DATE_FORMATS = ['en' => 'Y-m-d', 'fr' => 'd/m/Y', 'de' => 'd.m.Y', 'es' => 'd/m/Y'];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly LocaleAwareInterface $translator,
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('locale_date', $this->localeDate(...))];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('app_locales', $this->locales(...))];
    }

    /**
     * "2026-11-08" in English, "08/11/2026" in French, "08.11.2026" in German. $style: date, datetime or time.
     */
    public function localeDate(?\DateTimeInterface $date, string $style = 'date'): string
    {
        if (null === $date) {
            return '';
        }
        $format = self::DATE_FORMATS[$this->locale()] ?? self::DATE_FORMATS['en'];

        return $date->format(match ($style) {
            'datetime' => $format.' H:i',
            'time' => 'H:i',
            default => $format,
        });
    }

    /**
     * @return array<string, string> code => name of the language in itself
     */
    public function locales(): array
    {
        return Locales::ENABLED;
    }

    private function locale(): string
    {
        // The translator follows LocaleSwitcher (e-mails in the recipient's language); the request otherwise.
        $locale = $this->translator->getLocale();

        return Locales::isEnabled($locale) ? $locale : ($this->requests->getCurrentRequest()?->getLocale() ?? 'en');
    }
}
