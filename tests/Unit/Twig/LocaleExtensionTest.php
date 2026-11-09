<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\LocaleExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Translator;

final class LocaleExtensionTest extends TestCase
{
    public function testDatesFollowTheLanguage(): void
    {
        $date = new \DateTimeImmutable('2026-11-08 14:05');
        $translator = new Translator('en');
        $extension = new LocaleExtension(new RequestStack(), $translator);

        self::assertSame('2026-11-08', $extension->localeDate($date));
        $translator->setLocale('fr');
        self::assertSame('08/11/2026 14:05', $extension->localeDate($date, 'datetime'));
        $translator->setLocale('de');
        self::assertSame('08.11.2026', $extension->localeDate($date));
        self::assertSame('14:05', $extension->localeDate($date, 'time'));
        self::assertSame('', $extension->localeDate(null));
    }

    public function testLanguagesAreNamedInThemselves(): void
    {
        $extension = new LocaleExtension(new RequestStack(), new Translator('en'));

        self::assertSame('Español', $extension->locales()['es']);
    }
}
