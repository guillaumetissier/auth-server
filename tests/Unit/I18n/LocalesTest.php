<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use App\I18n\Locales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LocalesTest extends TestCase
{
    public function testEnabledLanguages(): void
    {
        self::assertSame(['en', 'fr', 'de', 'es'], array_keys(Locales::ENABLED));
        self::assertTrue(Locales::isEnabled('de'));
        self::assertFalse(Locales::isEnabled('it'));
        self::assertFalse(Locales::isEnabled(null));
    }

    #[DataProvider('browsers')]
    public function testTheFirstEnabledLanguageOfTheBrowser(string $acceptLanguage, ?string $expected): void
    {
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);

        self::assertSame($expected, Locales::fromBrowser($request));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function browsers(): iterable
    {
        yield 'region' => ['fr-CH,fr;q=0.9,en;q=0.8', 'fr'];
        yield 'first enabled' => ['it-IT,it;q=0.9,es;q=0.5', 'es'];
        yield 'none enabled' => ['it,pt', null];
        yield 'empty' => ['', null];
    }
}
