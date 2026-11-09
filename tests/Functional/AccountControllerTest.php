<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Event\AccountEvent;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;

/**
 * The user's own profile (App\Controller\AccountController): e-mail address and password.
 * Two-factor enrollment is covered by TwoFactorRolloutTest and TwoFactorAndLockoutTest.
 */
final class AccountControllerTest extends OAuthTestCase
{
    public function testProfileShowsTheAccountAndOffersTwoFactor(): void
    {
        $this->createUser(withTotp: false);
        $this->submitLogin();
        $this->browser->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'My profile');
        self::assertSelectorTextContains('dl', 'app-1001');
        self::assertSelectorTextContains('.badge', 'not enabled');
        self::assertSelectorExists('a[href="/account/2fa/setup"]');
    }

    public function testTheLanguageIsSavedOnTheAccount(): void
    {
        $this->createUser(withTotp: false);
        $this->submitLogin();
        $this->browser->request('GET', '/account');

        $this->browser->submitForm('Save the language', ['locale' => 'fr']);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'Langue enregistrée.');
        self::assertSelectorTextContains('h1', 'Mon profil');
        $this->em()->clear();
        self::assertSame('fr', $this->userNamed('alice')->getLocale());

        $this->browser->submitForm('Enregistrer la langue', ['locale' => '']);
        $this->em()->clear();
        self::assertNull($this->userNamed('alice')->getLocale(), 'back to the language of the browser');
    }

    public function testEmailChangeNeedsTheCurrentPassword(): void
    {
        $this->createUser(withTotp: false);
        $this->submitLogin();
        $this->browser->request('GET', '/account');

        $this->browser->submitForm('Save the e-mail address', ['email' => 'alice@example.test', 'current_password' => 'wrong password']);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'current password is not correct');
        self::assertNull($this->userNamed('alice')->getEmail());

        $this->browser->submitForm('Save the e-mail address', ['email' => 'Alice@Example.test', 'current_password' => self::PASSWORD]);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'E-mail address saved.');
        $this->em()->clear();
        self::assertSame('alice@example.test', $this->userNamed('alice')->getEmail());
        self::assertContains(AccountEvent::USER_UPDATED, array_column(self::webhooks(), 'type'));
    }

    public function testPasswordChangeKeepsThisSessionAndEndsTheOthers(): void
    {
        $this->createUser(withTotp: false);
        $this->submitLogin();
        $otherSession = $this->browser->getCookieJar()->all();

        $this->browser->getCookieJar()->clear();
        $this->submitLogin();
        $this->browser->request('GET', '/account');
        $this->browser->submitForm('Change the password', [
            'current_password' => self::PASSWORD,
            'password' => 'my new long passphrase',
            'confirmation' => 'my new long passphrase',
        ]);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'Password changed.');
        $this->browser->request('GET', '/dashboard');
        self::assertResponseIsSuccessful('this session goes on');
        self::assertContains(WebhookEvent::USER_PASSWORD_CHANGED, array_column(self::webhooks(), 'type'));

        $this->browser->getCookieJar()->clear();
        foreach ($otherSession as $cookie) {
            $this->browser->getCookieJar()->set($cookie);
        }
        $this->browser->request('GET', '/dashboard');
        self::assertResponseRedirects('http://localhost/login', null, 'the other session ends');
    }
}
