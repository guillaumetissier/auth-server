<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Settings\SystemSettings;

/**
 * The language of each request (App\I18n\LocaleSubscriber): the session's, else the browser's, else the
 * default language of the Settings page; at sign-in, the user's own.
 */
final class LocaleSubscriberTest extends OAuthTestCase
{
    public function testTheBrowserLanguageIsUsed(): void
    {
        $this->browser->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9']);

        self::assertSelectorExists('html[lang=es]');
        self::assertSelectorTextContains('a[href="/password/forgot"]', '¿Ha olvidado su contraseña?');
    }

    public function testOtherwiseTheDefaultLanguageOfTheSettings(): void
    {
        [$alice] = $this->createUser(withTotp: false);
        static::getContainer()->get(SystemSettings::class)->save([SystemSettings::DEFAULT_LOCALE => 'fr'], $alice);
        $this->em()->flush();

        $this->browser->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'it-IT,it']);

        self::assertSelectorExists('html[lang=fr]');
        self::assertSelectorTextContains('button', 'Continuer');
    }

    public function testTheUserLanguageAppliesFromTheSignIn(): void
    {
        [$alice] = $this->createUser(withTotp: false);
        $alice->setLocale('de');
        $this->em()->flush();

        $this->submitLogin();
        $this->browser->request('GET', '/account', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertSelectorTextContains('h1', 'Mein Profil');
    }
}
