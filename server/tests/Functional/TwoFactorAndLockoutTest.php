<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\LoginAttemptSubscriber;
use GuillaumeTissier\OAuthResourceGuard\Webhook\WebhookEvent;

final class TwoFactorAndLockoutTest extends OAuthTestCase
{
    public function testUserWithoutTotpEnrollsBeforeBeingRedirectedToTheClient(): void
    {
        $this->createUser(withTotp: false);
        $pkce = self::pkce();

        $this->browser->request('GET', self::authorizeUrl($pkce['challenge']));
        $this->submitLogin();
        $this->browser->followRedirect(); // /authorize
        self::assertResponseRedirects();
        self::assertStringStartsWith('/account/2fa/setup', (string) $this->browser->getResponse()->headers->get('Location'));

        $crawler = $this->browser->followRedirect();
        $secret = str_replace(' ', '', $crawler->filter('.secret strong')->text());

        // Wrong code: stays on the page.
        $this->browser->submit($crawler->selectButton('Activate')->form(['code' => '000000' === self::totp($secret) ? '111111' : '000000']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert.error', 'not valid');

        $crawler = $this->browser->submit($this->browser->getCrawler()->selectButton('Activate')->form(['code' => self::totp($secret)]));
        $crawler = $this->browser->followRedirect();
        self::assertCount(10, $crawler->filter('ol.codes li'), 'backup codes are shown once');

        // "Continue" goes back to the authorization request, which now completes.
        $this->browser->click($crawler->selectLink('I have saved them, continue')->link());
        self::codeFromRedirect();

        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => 'alice']);
        self::assertNotNull($user->getEncryptedTotpSecret());
        self::assertStringNotContainsString($secret, (string) $user->getEncryptedTotpSecret(), 'TOTP secret must be encrypted at rest');
    }

    public function testBackupCodeCanReplaceTheTotpCodeOnce(): void
    {
        [$user] = $this->createUser();
        $codes = static::getContainer()->get(\App\TwoFactor\TotpEnrollment::class)->regenerateBackupCodes($user);

        $this->browser->request('GET', self::authorizeUrl(self::pkce()['challenge']));
        $this->submitLogin();
        $this->browser->followRedirect(); // /authorize -> /2fa
        $this->submitTotp($codes[0]);
        $this->browser->followRedirect();
        self::codeFromRedirect();

        // Second use of the same backup code fails.
        $this->browser->request('GET', '/logout');
        $this->submitLogin();
        $this->submitTotp($codes[0]);
        self::assertResponseRedirects();
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'not valid');
    }

    public function testWrongPasswordGivesGenericError(): void
    {
        $this->createUser();

        $this->submitLogin('alice', 'wrong password');
        $this->browser->followRedirect();
        $known = $this->browser->getCrawler()->filter('.alert.error')->text();

        $this->submitLogin('nobody', 'wrong password', '10.0.0.2');
        $this->browser->followRedirect();
        $unknown = $this->browser->getCrawler()->filter('.alert.error')->text();

        self::assertSame($known, $unknown, 'no user enumeration');
    }

    public function testAccountIsLockedAfterRepeatedFailuresFromAnyIp(): void
    {
        $this->createUser();

        for ($i = 1; $i <= LoginAttemptSubscriber::MAX_ATTEMPTS; ++$i) {
            // A different IP each time: the per-IP throttling does not kick in, the account lock does.
            $this->submitLogin('alice', 'wrong password', '10.1.0.'.$i);
        }

        // Even the right password is refused while locked.
        $this->submitLogin('alice', self::PASSWORD, '10.2.0.1');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'temporarily locked');

        self::assertContains(WebhookEvent::USER_LOCKED, array_column(self::webhooks(), 'type'));
    }

    public function testTotpCodeCannotBeReplayed(): void
    {
        [, $secret] = $this->createUser();
        $code = self::totp($secret);

        $this->submitLogin();
        $this->submitTotp($code);
        $this->browser->request('GET', '/logout');

        $this->submitLogin();
        $this->submitTotp($code);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'already been used');
    }

    public function testCredentialPagesCannotBeFramed(): void
    {
        $this->browser->request('GET', '/login');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertStringContainsString("frame-ancestors 'none'", (string) $this->browser->getResponse()->headers->get('Content-Security-Policy'));
    }
}
