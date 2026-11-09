<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Account\PasswordLinks;
use GuillaumeTissier\ResourceGuard\Client\DpopProofFactory;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Symfony\Component\Mime\Email;

/**
 * "Forgot your password?" and the password link page (App\Controller\PasswordController).
 */
final class PasswordControllerTest extends OAuthTestCase
{
    public function testForgottenPasswordByEmailThenNewPassword(): void
    {
        [$alice] = $this->createUser(withTotp: false);
        $alice->setEmail('alice@example.test');
        $this->em()->flush();

        $this->browser->request('GET', '/login');
        $this->browser->clickLink('Forgot your password?');
        $this->browser->submitForm('Send the link', ['identifier' => 'ALICE@example.test']);
        self::assertSelectorTextContains('.alert.ok', 'If an account matches');

        $link = $this->linkFromLastEmail();
        $this->browser->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertSelectorTextContains('main', 'For the account alice');

        $this->browser->submitForm('Save the password', ['password' => 'short', 'confirmation' => 'short']);
        self::assertSelectorTextContains('.alert.error', 'at least 12 characters');
        $this->browser->submitForm('Save the password', ['password' => 'a brand new passphrase', 'confirmation' => 'another one entirely']);
        self::assertSelectorTextContains('.alert.error', 'The two passwords are different.');
        $this->browser->submitForm('Save the password', ['password' => 'a brand new passphrase', 'confirmation' => 'a brand new passphrase']);
        self::assertResponseRedirects('/login');
        self::assertContains(WebhookEvent::USER_PASSWORD_CHANGED, array_column(self::webhooks(), 'type'));

        $this->browser->request('GET', $link);
        self::assertResponseStatusCodeSame(404, 'a link works once');
        self::assertSelectorTextContains('h1', 'Link not valid');

        $this->submitLogin('alice', 'a brand new passphrase');
        self::assertResponseRedirects('http://localhost/dashboard');
        $this->browser->getCookieJar()->clear();
        $this->submitLogin('alice');
        $this->browser->followRedirect();
        self::assertSelectorExists('.alert.error', 'the old password no longer works');
    }

    public function testTheEmailIsInTheLanguageOfItsRecipient(): void
    {
        [$alice] = $this->createUser(withTotp: false);
        $alice->setEmail('alice@example.test');
        $alice->setLocale('es');
        $this->em()->flush();

        $this->browser->request('GET', '/password/forgot');
        $this->browser->submitForm('Send the link', ['identifier' => 'alice']);
        self::assertSelectorTextContains('.alert.ok', 'If an account matches', 'the page stays in the language of the visitor');

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Elegir una nueva contraseña', $email->getSubject());
        self::assertStringContainsString('Hola, alice', (string) $email->getTextBody());
    }

    public function testTheAnswerDoesNotRevealWhoHasAnAccount(): void
    {
        [$bob] = $this->createUser('bob', 'app-bob', withTotp: false); // no e-mail address

        foreach (['nobody', 'bob'] as $identifier) {
            $this->browser->request('GET', '/password/forgot');
            $this->browser->submitForm('Send the link', ['identifier' => $identifier]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.alert.ok', 'If an account matches');
            self::assertEmailCount(0);
        }
    }

    public function testRequestsAreRateLimited(): void
    {
        [$alice] = $this->createUser(withTotp: false);
        $alice->setEmail('alice@example.test');
        $this->em()->flush();

        $sent = 0;
        for ($i = 0; $i < 7; ++$i) {
            $this->browser->request('GET', '/password/forgot');
            $this->browser->submitForm('Send the link', ['identifier' => 'alice']);
            $sent += \count(self::getMailerMessages());
        }
        self::assertSame(5, $sent, '5 links per 15 minutes');
    }

    public function testExpiredLinkIsRefused(): void
    {
        [$alice] = $this->createUser(withTotp: false);
        $alice->setEmail('alice@example.test');
        $this->em()->flush();
        static::getContainer()->get(PasswordLinks::class)->send($alice, PasswordLinks::RESET);
        $link = $this->linkFromLastEmail();

        $this->em()->getConnection()->executeStatement(
            'UPDATE users SET password_link_expires_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s'), $alice->getId()],
        );
        $this->em()->clear();

        $this->browser->request('GET', $link);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNewPasswordRevokesTheTokensOfTheApplications(): void
    {
        [$alice, $secret] = $this->createUser();
        $alice->setEmail('alice@example.test');
        $this->em()->flush();
        $pkce = self::pkce();
        $key = DpopProofFactory::withNewKey();
        $code = $this->obtainAuthorizationCode($pkce['challenge'], $secret);
        [, $tokens] = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $pkce['verifier']], $key);

        static::getContainer()->get(PasswordLinks::class)->send($this->userNamed('alice'), PasswordLinks::RESET);
        $this->browser->request('GET', $this->linkFromLastEmail());
        $this->browser->submitForm('Save the password', ['password' => 'a brand new passphrase', 'confirmation' => 'a brand new passphrase']);

        [$status, $body] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => self::stringAt($tokens, 'refresh_token')], $key);
        self::assertSame(400, $status, (string) json_encode($body));
        self::assertSame('invalid_grant', $body['error'] ?? null);
    }

    private function linkFromLastEmail(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        if (1 !== preg_match('#http://localhost(/password/set/[A-Za-z0-9_-]+)#', (string) $email->getTextBody(), $m)) {
            self::fail('No password link in the e-mail.');
        }

        return $m[1];
    }
}
