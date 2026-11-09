<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\LocaleController;

/**
 * The language links of every page (App\Controller\LocaleController).
 */
final class LocaleControllerTest extends OAuthTestCase
{
    public function testALanguageLinkChangesTheLanguageOfThisSession(): void
    {
        $this->browser->request('GET', '/login');
        self::assertSelectorTextContains('button', 'Continue');

        $this->browser->clickLink('Deutsch');
        self::assertResponseRedirects('/login');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('button', 'Weiter');
        self::assertSelectorExists('html[lang=de]');
        self::assertSelectorTextContains('nav.languages strong', 'Deutsch');

        $this->browser->request('GET', '/password/forgot');
        self::assertSelectorTextContains('button', 'Link senden', 'the language stays for the session');
    }

    public function testAnUnknownLanguageChangesNothing(): void
    {
        $this->browser->request('GET', '/language/it?return=/login');
        self::assertResponseRedirects('/login');
        $this->browser->followRedirect();
        self::assertSelectorExists('html[lang=en]');
    }

    public function testOnlyReturnsToThisServer(): void
    {
        self::assertSame('/login?x=1', LocaleController::safeReturn('/login?x=1'));
        self::assertSame('/', LocaleController::safeReturn('https://evil.test/'));
        self::assertSame('/', LocaleController::safeReturn('//evil.test/'));
        self::assertSame('/', LocaleController::safeReturn('/\\evil.test/'));
        self::assertSame('/', LocaleController::safeReturn(''));
    }
}
