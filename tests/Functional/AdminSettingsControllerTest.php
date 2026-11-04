<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ClientPolicy;
use App\Settings\SystemSettings;
use GuillaumeTissier\ResourceGuard\Client\DpopProofFactory;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;

/**
 * System settings page (App\Controller\AdminSettingsController) and their effect on sign-in and tokens.
 */
final class AdminSettingsControllerTest extends AdminTestCase
{
    public function testSuperAdministratorMakesTwoFactorRequiredFromADate(): void
    {
        $this->signIn('root');
        $this->browser->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select#tf-mode option[value="required"][selected]', 'default of .env.test: required');
        self::assertSelectorTextContains('main', 'Default of the server configuration');

        $this->browser->submitForm('Save the settings', ['two_factor_mode' => 'grace', 'two_factor_deadline' => '2020-01-01']);
        self::assertResponseRedirects('/admin/settings');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'Settings saved.');
        self::assertSelectorTextContains('main', 'Set by root on');

        // The deadline has passed: reader may no longer postpone.
        $this->signIn('reader');
        $this->browser->request('GET', self::authorizeUrl(self::pkce()['challenge']));
        self::assertStringStartsWith('/account/2fa/setup', (string) $this->browser->getResponse()->headers->get('Location'));
        $this->browser->followRedirect();
        self::assertSelectorNotExists('button:contains("Later")');
        self::assertSelectorTextContains('main', 'required for your account');
    }

    public function testOptionalTwoFactorIsNoLongerAsked(): void
    {
        $this->saveSettings([SystemSettings::TWO_FACTOR_MODE => 'optional', SystemSettings::TWO_FACTOR_DEADLINE => '']);

        $this->signIn('reader');
        $this->browser->request('GET', self::authorizeUrl(self::pkce()['challenge']));
        self::assertResponseRedirects();
        self::assertStringStartsWith(self::REDIRECT_URI.'?code=', (string) $this->browser->getResponse()->headers->get('Location'));
    }

    public function testDpopCanBeMadeOptionalForAllOrForOneApplication(): void
    {
        $this->saveSettings([SystemSettings::TWO_FACTOR_MODE => 'optional']);
        $this->signIn('reader');
        $code = $this->code();
        [$status, $body] = $this->exchange($code, null);
        self::assertSame(400, $status, 'DPoP required by default');
        self::assertSame('invalid_dpop_proof', $body['error'] ?? null);

        $this->saveSettings([SystemSettings::DPOP_REQUIRED => '0']);
        $this->signIn('reader');
        [$status, $body] = $this->exchange($this->code(), null);
        self::assertSame(200, $status, (string) json_encode($body));
        self::assertSame('Bearer', $body['token_type'] ?? null);

        // The application's own rule beats the setting.
        $this->signIn('root');
        $this->browser->request('GET', '/admin/settings');
        $this->submitForm('/admin/settings/clients/'.self::CLIENT_ID, ['dpop' => 'required']);
        self::assertResponseRedirects('/admin/settings');
        $this->em()->clear();
        self::assertTrue($this->em()->getRepository(ClientPolicy::class)->findOneBy(['client' => self::CLIENT_ID])?->requiresDpop());

        $this->signIn('reader');
        [$status] = $this->exchange($this->code(), null);
        self::assertSame(400, $status);
        $this->signIn('reader');
        [$status, $body] = $this->exchange($this->code(), DpopProofFactory::withNewKey());
        self::assertSame(200, $status);
        self::assertSame('DPoP', $body['token_type'] ?? null);
    }

    public function testPkceStaysMandatoryForPublicClientsAndBecomesOptionalForConfidentialOnes(): void
    {
        $confidential = new Client('Back office', 'back-office', 'a-long-client-secret');
        $confidential->setRedirectUris(new RedirectUri('http://back-office.test/callback'));
        $confidential->setGrants(new Grant('authorization_code'));
        $confidential->setScopes(new Scope('profile'));
        static::getContainer()->get(ClientManagerInterface::class)->save($confidential);

        $withoutPkce = static fn (string $client, string $redirect): string => '/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $client, 'redirect_uri' => $redirect, 'scope' => 'profile', 'state' => 'xyz',
        ]);
        $this->saveSettings([SystemSettings::TWO_FACTOR_MODE => 'optional']);
        $this->signIn('reader');

        $this->browser->request('GET', $withoutPkce('back-office', 'http://back-office.test/callback'));
        self::assertResponseStatusCodeSame(400, 'PKCE for confidential clients by default');

        $this->saveSettings([SystemSettings::PKCE_CONFIDENTIAL => '0']);
        $this->signIn('reader');
        $this->browser->request('GET', $withoutPkce('back-office', 'http://back-office.test/callback'));
        self::assertStringStartsWith('http://back-office.test/callback?code=', (string) $this->browser->getResponse()->headers->get('Location'));

        $this->browser->request('GET', $withoutPkce(self::CLIENT_ID, self::REDIRECT_URI));
        self::assertResponseStatusCodeSame(400, 'never optional for a public client');
        $this->browser->request('GET', self::authorizeUrl(str_repeat('a', 50), ['client_id' => 'back-office', 'redirect_uri' => 'http://back-office.test/callback', 'code_challenge_method' => 'plain']));
        self::assertResponseStatusCodeSame(400, '"plain" is never accepted');
    }

    public function testTokenLifetimesApplyToTheNextTokens(): void
    {
        $this->saveSettings([SystemSettings::TWO_FACTOR_MODE => 'optional']);
        $this->signIn('root');
        $this->browser->request('GET', '/admin/settings');
        self::assertSelectorExists('input[name=tokens_access_ttl_amount][value="15"]');
        $this->browser->submitForm('Save the settings', [
            'tokens_access_ttl_amount' => '2', 'tokens_access_ttl_unit' => 'minutes',
            'tokens_refresh_ttl_amount' => '2', 'tokens_refresh_ttl_unit' => 'days',
            'impersonation_session_ttl_amount' => '1', 'impersonation_session_ttl_unit' => 'hours',
            'impersonation_access_ttl_amount' => '3', 'impersonation_access_ttl_unit' => 'minutes',
        ]);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'Settings saved.');

        $this->signIn('reader');
        [$status, $body] = $this->exchange($this->code(), DpopProofFactory::withNewKey());
        self::assertSame(200, $status);
        self::assertSame(120, $body['expires_in'] ?? null, 'access token: 2 minutes');

        $refresh = $this->em()->createQueryBuilder()->select('r')->from(\League\Bundle\OAuth2ServerBundle\Model\RefreshToken::class, 'r')
            ->orderBy('r.expiry', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(\League\Bundle\OAuth2ServerBundle\Model\RefreshToken::class, $refresh);
        self::assertEqualsWithDelta((new \DateTimeImmutable('+2 days'))->getTimestamp(), $refresh->getExpiry()->getTimestamp(), 60, 'refresh token: 2 days');

        $impersonation = static::getContainer()->get(\App\Impersonation\ImpersonationPolicy::class);
        self::assertSame('01:00', $impersonation->sessionTtl()->format('%H:%I'));
        self::assertSame('00:03', $impersonation->accessTokenTtl()->format('%H:%I'));
    }

    public function testInconsistentLifetimesAreRefused(): void
    {
        $this->signIn('root');
        $cases = [
            [['tokens_refresh_ttl_amount' => '10', 'tokens_refresh_ttl_unit' => 'minutes'], 'between 1 hour and 365 days'],
            [['tokens_access_ttl_amount' => '2', 'tokens_access_ttl_unit' => 'days'], 'between 1 minute and 1 day'],
            [['tokens_access_ttl_amount' => '0'], 'whole number'],
            [['tokens_access_ttl_amount' => 'ten'], 'whole number'],
            [['tokens_access_ttl_amount' => '1', 'tokens_access_ttl_unit' => 'days', 'tokens_refresh_ttl_amount' => '1', 'tokens_refresh_ttl_unit' => 'days'], 'must live longer than the access token'],
            [['impersonation_session_ttl_amount' => '10', 'impersonation_access_ttl_amount' => '20', 'impersonation_session_ttl_unit' => 'minutes', 'impersonation_access_ttl_unit' => 'minutes'], 'cannot live longer than the session'],
        ];
        foreach ($cases as [$values, $message]) {
            $this->browser->request('GET', '/admin/settings');
            $this->browser->submitForm('Save the settings', $values);
            $this->browser->followRedirect();
            self::assertSelectorTextContains('.alert.error', $message, (string) json_encode($values));
        }
        self::assertNull(static::getContainer()->get(SystemSettings::class)->row(SystemSettings::ACCESS_TOKEN_TTL));
    }

    public function testDefaultsComeBack(): void
    {
        $this->saveSettings([SystemSettings::DPOP_REQUIRED => '0', SystemSettings::TWO_FACTOR_MODE => 'required']);
        $this->signIn('root');
        $this->browser->request('GET', '/admin/settings');
        $this->browser->submitForm('Use the defaults of the server configuration');

        $settings = static::getContainer()->get(SystemSettings::class);
        $settings->reset();
        self::assertTrue($settings->dpopRequiredByDefault());
        self::assertSame('required', $settings->twoFactorMode(), 'APP_2FA_MODE of .env.test');
        self::assertNull($settings->row(SystemSettings::TWO_FACTOR_MODE));
    }

    public function testInvalidValuesAreRefused(): void
    {
        $this->signIn('root');
        $this->browser->request('GET', '/admin/settings');
        $token = (string) $this->browser->getCrawler()->filter('form[action="/admin/settings"] input[name=_csrf_token]')->attr('value');
        foreach ([['two_factor_mode' => 'sometimes', 'two_factor_deadline' => ''], ['two_factor_mode' => 'grace', 'two_factor_deadline' => '31/12/2026'], ['two_factor_mode' => 'grace', 'two_factor_deadline' => '2026-02-31']] as $values) {
            $this->browser->request('POST', '/admin/settings', $values + ['_csrf_token' => $token]);
            $this->browser->followRedirect();
            self::assertSelectorExists('.alert.error', (string) json_encode($values));
        }
        self::assertNull(static::getContainer()->get(SystemSettings::class)->row(SystemSettings::TWO_FACTOR_MODE));
    }

    public function testOthersOnlyLookOrDoNotSeeTheSettings(): void
    {
        $this->signIn('support');
        $this->browser->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sub', 'Only the super-administrator changes them.');
        self::assertSelectorNotExists('button:contains("Save the settings")');
        self::assertSelectorExists('input[name=dpop_required][disabled]');

        $this->signIn('manager');
        $this->browser->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/admin/users');
        self::assertSelectorNotExists('nav a[href="/admin/settings"]');
    }

    /**
     * @param array<string, string> $values
     */
    private function saveSettings(array $values): void
    {
        $settings = static::getContainer()->get(SystemSettings::class);
        $settings->save($values, $this->userNamed('root'));
        $this->em()->clear();
    }

    private function code(): string
    {
        $this->pkce = self::pkce();
        $this->browser->request('GET', self::authorizeUrl($this->pkce['challenge']));

        return $this->codeFromRedirect();
    }

    /** @var array{verifier: string, challenge: string} */
    private array $pkce;

    /**
     * @return array{int, array<mixed>}
     */
    private function exchange(string $code, ?DpopProofFactory $key): array
    {
        return $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $this->pkce['verifier']], $key);
    }
}
