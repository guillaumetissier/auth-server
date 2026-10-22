<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Impersonation\ImpersonationManager;
use GuillaumeTissier\ResourceGuard\Client\DpopProofFactory;
use GuillaumeTissier\ResourceGuard\Dpop\DpopProofValidator;
use GuillaumeTissier\ResourceGuard\Dpop\InMemoryReplayStore;
use GuillaumeTissier\ResourceGuard\Guard\AuthenticationFailed;
use GuillaumeTissier\ResourceGuard\Guard\ResourceGuard;
use GuillaumeTissier\ResourceGuard\Token\AccessTokenValidator;
use GuillaumeTissier\ResourceGuard\Token\Impersonation;
use GuillaumeTissier\ResourceGuard\Token\StaticJwksProvider;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;

final class ImpersonationTest extends OAuthTestCase
{
    private const API = 'https://api.test/api/items';
    private const ALL_SCOPES = 'profile offline_access items.read items.write';

    public function testAdminImpersonatesReadOnly(): void
    {
        $this->createUser('alice', 'app-1001', withTotp: false);
        $adminSecret = $this->createAdmin('carla', 'app-9001', User::ROLE_ADMIN);
        $pkce = self::pkce();
        $key = DpopProofFactory::withNewKey();

        $crawler = $this->reachConfirmation('carla', $adminSecret, $pkce['challenge'], 'app-1001');
        self::assertSelectorTextContains('h1', 'alice');
        self::assertSelectorTextContains('.sub', 'read-only');

        // Reason and a valid code are both required.
        $this->browser->submit($crawler->selectButton('Start')->form(['reason' => 'short', 'code' => '000000']));
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.alert.error');

        $this->confirm($adminSecret);
        $code = $this->codeFromRedirect();

        [$status, $tokens] = $this->tokenRequest($this->codeParams($code, $pkce['verifier']), $key);
        self::assertSame(200, $status, (string) json_encode($tokens));
        self::assertLessThanOrEqual(300, $tokens['expires_in'], 'short-lived access token during an impersonation');

        $claims = self::jwtClaims(self::stringAt($tokens, 'access_token'));
        self::assertSame('app-1001', $claims['sub'], 'the token is the impersonated user\'s');
        self::assertSame(['sub' => 'app-9001'], $claims['act'], 'the administrator is recorded as the actor');
        self::assertSame(Impersonation::MODE_READ_ONLY, $claims['imp_mode']);
        self::assertSame(['profile', 'offline_access', 'items.read'], $claims['scopes'], 'write scopes are dropped');

        // Resource server: reads pass, writes are refused whatever the scopes.
        $guard = $this->resourceGuard();
        $principal = $guard->authenticate('GET', self::API, 'DPoP '.self::stringAt($tokens, 'access_token'), [$key->create('GET', self::API, self::stringAt($tokens, 'access_token'))]);
        self::assertSame('app-1001', $principal->userId);
        self::assertSame('app-9001', $principal->actorId());
        try {
            $guard->authenticate('POST', self::API, 'DPoP '.self::stringAt($tokens, 'access_token'), [$key->create('POST', self::API, self::stringAt($tokens, 'access_token'))]);
            self::fail('Writes must be refused during a read-only impersonation');
        } catch (AuthenticationFailed $e) {
            self::assertSame('impersonation_read_only', $e->errorCode);
        }

        $started = $this->webhook(WebhookEvent::IMPERSONATION_STARTED);
        self::assertSame('app-1001', self::arrayAt($started, 'data')['user_id'] ?? null);
        self::assertSame('app-9001', self::arrayAt($started, 'data')['actor_id'] ?? null);
        self::assertSame('Support ticket #4711, item list issue', self::arrayAt($started, 'data')['reason'] ?? null);
    }

    public function testSuperAdminHasFullRightsAndMayImpersonateAnAdmin(): void
    {
        $this->createAdmin('carla', 'app-9001', User::ROLE_ADMIN);
        $superSecret = $this->createAdmin('frank', 'app-1', User::ROLE_SUPER_ADMIN);
        $pkce = self::pkce();
        $key = DpopProofFactory::withNewKey();

        $this->reachConfirmation('frank', $superSecret, $pkce['challenge'], 'app-9001');
        self::assertSelectorTextContains('.sub', 'all the rights');
        $this->confirm($superSecret);

        [, $tokens] = $this->tokenRequest($this->codeParams($this->codeFromRedirect(), $pkce['verifier']), $key);
        $claims = self::jwtClaims(self::stringAt($tokens, 'access_token'));
        self::assertSame('app-9001', $claims['sub']);
        self::assertSame(Impersonation::MODE_FULL, $claims['imp_mode']);
        self::assertContains('items.write', self::arrayAt($claims, 'scopes'));

        $principal = $this->resourceGuard()->authenticate('POST', self::API, 'DPoP '.self::stringAt($tokens, 'access_token'), [$key->create('POST', self::API, self::stringAt($tokens, 'access_token'))]);
        self::assertFalse($principal->isReadOnly());
    }

    public function testRegularUserCannotImpersonate(): void
    {
        [, $secret] = $this->createUser('alice', 'app-1001');
        $this->createUser('bob', 'app-1002', withTotp: false);

        $this->loginThroughAuthorize('alice', $secret, self::impersonateUrl(self::pkce()['challenge'], 'app-1002'));

        $this->assertAccessDenied();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenTargets(): iterable
    {
        yield 'admin impersonating an admin' => [User::ROLE_ADMIN, User::ROLE_ADMIN];
        yield 'admin impersonating the super-admin' => [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN];
        yield 'super-admin impersonating the super-admin' => [User::ROLE_SUPER_ADMIN, User::ROLE_SUPER_ADMIN];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenTargets')]
    public function testForbiddenTargets(string $adminRole, string $targetRole): void
    {
        $secret = $this->createAdmin('carla', 'app-9001', $adminRole);
        $this->createAdmin('target', 'app-9002', $targetRole);

        $this->loginThroughAuthorize('carla', $secret, self::impersonateUrl(self::pkce()['challenge'], 'app-9002'));

        $this->assertAccessDenied();
    }

    public function testConfirmationIsSingleUseAndBoundToItsRequest(): void
    {
        $this->createUser('alice', 'app-1001', withTotp: false);
        $secret = $this->createAdmin('carla', 'app-9001', User::ROLE_ADMIN);
        $challenge = self::pkce()['challenge'];

        $this->reachConfirmation('carla', $secret, $challenge, 'app-1001');
        $code = self::totp($secret); // captured once: a 30 s window boundary must not change it
        $this->confirm($secret, $code);
        $this->codeFromRedirect();

        // Same request again (or another one): a new confirmation is required.
        $this->browser->request('GET', self::impersonateUrl($challenge, 'app-1001'));
        self::assertStringStartsWith('/impersonation/confirm', (string) $this->browser->getResponse()->headers->get('Location'));

        // The TOTP code used for the first confirmation is refused for the second one.
        $this->browser->followRedirect();
        $this->browser->submit($this->browser->getCrawler()->selectButton('Start')->form([
            'reason' => 'Support ticket #4711, item list issue',
            'code' => $code,
        ]));
        self::assertSelectorTextContains('.alert.error', 'authentication code is not valid');
    }

    public function testEndingTheImpersonationRevokesItsRefreshToken(): void
    {
        [$tokens, $key] = $this->impersonateAs(User::ROLE_ADMIN);

        $this->browser->request('POST', '/impersonation/end', server: [
            'HTTP_AUTHORIZATION' => 'DPoP '.self::stringAt($tokens, 'access_token'),
            'HTTP_DPOP' => $key->create('POST', 'http://localhost/impersonation/end', self::stringAt($tokens, 'access_token')),
        ]);
        self::assertResponseStatusCodeSame(204);

        [$status, $error] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => self::stringAt($tokens, 'refresh_token')], $key);
        self::assertSame(400, $status);
        self::assertSame('invalid_grant', self::stringAt($error, 'error'));

        $ended = $this->webhook(WebhookEvent::IMPERSONATION_ENDED);
        self::assertSame(ImpersonationSession::END_BY_ADMIN, self::arrayAt($ended, 'data')['end_reason'] ?? null);
    }

    public function testRefreshWorksDuringTheSessionButNotAfterItsExpiry(): void
    {
        [$tokens, $key] = $this->impersonateAs(User::ROLE_ADMIN);

        [$status, $refreshed] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => self::stringAt($tokens, 'refresh_token')], $key);
        self::assertSame(200, $status, (string) json_encode($refreshed));
        self::assertSame(['sub' => 'app-9001'], self::jwtClaims(self::stringAt($refreshed, 'access_token'))['act'], 'refreshed tokens keep the actor');

        $this->em()->createQuery('UPDATE '.ImpersonationSession::class.' s SET s.expiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))->execute();

        [$status] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => self::stringAt($refreshed, 'refresh_token')], $key);
        self::assertSame(400, $status);

        self::assertSame(1, static::getContainer()->get(ImpersonationManager::class)->closeExpired());
    }

    public function testThereIsOnlyOneSuperAdmin(): void
    {
        $this->createUser('frank', 'app-1', withTotp: false);
        $this->createUser('carla', 'app-9001', withTotp: false);
        $tester = $this->command('app:user:role');

        self::assertSame(0, $tester->execute(['username' => 'frank', 'role' => 'ROLE_SUPER_ADMIN']));
        self::assertSame(1, $tester->execute(['username' => 'carla', 'role' => 'ROLE_SUPER_ADMIN']));
        self::assertStringContainsString('only one', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['username' => 'carla', 'role' => 'ROLE_SUPER_ADMIN', '--transfer' => true]));
        $this->em()->clear();
        self::assertFalse($this->userNamed('frank')->hasRole(User::ROLE_SUPER_ADMIN));
        self::assertTrue($this->userNamed('carla')->hasRole(User::ROLE_SUPER_ADMIN));
    }

    // --- helpers ------------------------------------------------------------------------

    private function createAdmin(string $username, string $externalId, string $role): string
    {
        [$user, $secret] = $this->createUser($username, $externalId);
        $user->addRole($role);
        $this->em()->flush();

        return $secret;
    }

    private static function impersonateUrl(string $challenge, string $targetId): string
    {
        return self::authorizeUrl($challenge, ['impersonate' => $targetId, 'scope' => self::ALL_SCOPES]);
    }

    private function loginThroughAuthorize(string $username, string $secret, string $authorizeUrl): void
    {
        $this->browser->request('GET', $authorizeUrl);
        $this->submitLogin($username);
        $this->browser->followRedirect(); // /authorize -> /2fa
        $this->submitTotp(self::totp($secret));
        $this->browser->followRedirect(); // back to /authorize
    }

    private function reachConfirmation(string $username, string $secret, string $challenge, string $targetId): \Symfony\Component\DomCrawler\Crawler
    {
        $this->loginThroughAuthorize($username, $secret, self::impersonateUrl($challenge, $targetId));
        self::assertStringStartsWith('/impersonation/confirm', (string) $this->browser->getResponse()->headers->get('Location'));

        return $this->browser->followRedirect();
    }

    private function confirm(string $secret, ?string $code = null): void
    {
        $this->browser->submit($this->browser->getCrawler()->selectButton('Start')->form([
            'reason' => 'Support ticket #4711, item list issue',
            'code' => $code ?? self::totp($secret),
        ]));
        self::assertResponseRedirects();
        $this->browser->followRedirect(); // back to /authorize, now confirmed
    }

    /**
     * @return array{array<mixed>, DpopProofFactory}
     */
    private function impersonateAs(string $role): array
    {
        $this->createUser('alice', 'app-1001', withTotp: false);
        $secret = $this->createAdmin('carla', 'app-9001', $role);
        $pkce = self::pkce();
        $key = DpopProofFactory::withNewKey();

        $this->reachConfirmation('carla', $secret, $pkce['challenge'], 'app-1001');
        $this->confirm($secret);
        [$status, $tokens] = $this->tokenRequest($this->codeParams($this->codeFromRedirect(), $pkce['verifier']), $key);
        self::assertSame(200, $status, (string) json_encode($tokens));

        return [$tokens, $key];
    }

    /**
     * @return array<string, string>
     */
    private function codeParams(string $code, string $verifier): array
    {
        return ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $verifier];
    }

    private function assertAccessDenied(): void
    {
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('access_denied', $query['error'] ?? null);
        self::assertSame('xyz', $query['state'] ?? null);
        self::assertArrayNotHasKey('code', $query);
    }

    private function resourceGuard(): ResourceGuard
    {
        $this->browser->request('GET', '/.well-known/jwks.json');

        return new ResourceGuard(
            new AccessTokenValidator(StaticJwksProvider::fromJwksJson((string) $this->browser->getResponse()->getContent())),
            new DpopProofValidator(new InMemoryReplayStore()),
        );
    }

    /**
     * @return array<mixed>
     */
    private function webhook(string $type): array
    {
        foreach (array_reverse(self::webhooks()) as $hook) {
            if ($type === $hook['type']) {
                return $hook;
            }
        }
        self::fail(\sprintf('No "%s" webhook was sent', $type));
    }
}
