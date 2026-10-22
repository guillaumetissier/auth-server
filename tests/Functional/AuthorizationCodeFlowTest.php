<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use GuillaumeTissier\ResourceGuard\Client\DpopProofFactory;
use GuillaumeTissier\ResourceGuard\Dpop\DpopProofValidator;
use GuillaumeTissier\ResourceGuard\Dpop\InMemoryReplayStore;
use GuillaumeTissier\ResourceGuard\Guard\AuthenticationFailed;
use GuillaumeTissier\ResourceGuard\Guard\ResourceGuard;
use GuillaumeTissier\ResourceGuard\Token\AccessTokenValidator;
use GuillaumeTissier\ResourceGuard\Token\StaticJwksProvider;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookSignature;
use App\Tests\Support\RecordingHttpClient;

/**
 * The complete journey: browser login + TOTP, Authorization Code + PKCE, DPoP-bound tokens,
 * verification by a resource server using guillaumetissier/resource-guard, refresh token rotation.
 */
final class AuthorizationCodeFlowTest extends OAuthTestCase
{
    public function testFullFlowIssuesDpopBoundTokensUsableOnAResourceServer(): void
    {
        [, $secret] = $this->createUser();
        $pkce = self::pkce();
        $key = DpopProofFactory::withNewKey();

        $code = $this->obtainAuthorizationCode($pkce['challenge'], $secret);

        [$status, $tokens] = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $pkce['verifier'],
        ], $key);

        self::assertSame(200, $status, json_encode($tokens));
        self::assertSame('DPoP', $tokens['token_type']);
        self::assertArrayHasKey('refresh_token', $tokens);
        self::assertSame('http://spa.test', $this->browser->getResponse()->headers->get('Access-Control-Allow-Origin'));

        $claims = self::jwtClaims($tokens['access_token']);
        self::assertSame('app-1001', $claims['sub'], 'sub must be the id of the main application');
        self::assertSame($key->thumbprint(), $claims['cnf']['jkt']);

        // A resource server verifies the token with the published JWKS + the DPoP proof.
        $this->browser->request('GET', '/.well-known/jwks.json');
        $guard = new ResourceGuard(
            new AccessTokenValidator(StaticJwksProvider::fromJwksJson((string) $this->browser->getResponse()->getContent()), [self::CLIENT_ID]),
            new DpopProofValidator(new InMemoryReplayStore()),
        );
        $api = 'https://api.test/api/items';
        $principal = $guard->authenticate('GET', $api, 'DPoP '.$tokens['access_token'], [$key->create('GET', $api, $tokens['access_token'])]);
        self::assertSame('app-1001', $principal->userId);
        self::assertTrue($principal->hasScope('profile'));

        // The same token is useless to whoever steals it without the private key.
        $thief = DpopProofFactory::withNewKey();
        try {
            $guard->authenticate('GET', $api, 'DPoP '.$tokens['access_token'], [$thief->create('GET', $api, $tokens['access_token'])]);
            self::fail('A stolen DPoP-bound token must be rejected');
        } catch (AuthenticationFailed $e) {
            self::assertSame('invalid_dpop_proof', $e->errorCode);
        }
    }

    public function testRefreshTokenIsBoundToTheDpopKeyAndRotated(): void
    {
        [, $secret] = $this->createUser();
        $pkce = self::pkce();
        $key = DpopProofFactory::withNewKey();
        $code = $this->obtainAuthorizationCode($pkce['challenge'], $secret);
        [, $tokens] = $this->tokenRequest([
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $pkce['verifier'],
        ], $key);

        // Another key cannot redeem the refresh token.
        [$status, $error] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']], DpopProofFactory::withNewKey());
        self::assertSame(400, $status);
        self::assertSame('invalid_grant', $error['error']);

        // Neither can a request without proof.
        [$status, $error] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']], null);
        self::assertSame(400, $status);
        self::assertSame('invalid_dpop_proof', $error['error']);

        // The legitimate client can, and gets a new pair bound to the same key.
        [$status, $refreshed] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']], $key);
        self::assertSame(200, $status, json_encode($refreshed));
        self::assertSame('DPoP', $refreshed['token_type']);
        self::assertSame($key->thumbprint(), self::jwtClaims($refreshed['access_token'])['cnf']['jkt']);

        // Rotation: the old refresh token is now revoked.
        [$status] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']], $key);
        self::assertSame(400, $status);
    }

    public function testAuthorizationCodeCannotBeRedeemedWithoutTheRightVerifier(): void
    {
        [, $secret] = $this->createUser();
        $pkce = self::pkce();
        $code = $this->obtainAuthorizationCode($pkce['challenge'], $secret);

        [$status, $error] = $this->tokenRequest([
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => self::pkce()['verifier'],
        ], DpopProofFactory::withNewKey());

        self::assertSame(400, $status);
        self::assertSame('invalid_grant', $error['error']);
    }

    public function testPkceWithS256IsMandatory(): void
    {
        $this->createUser(withTotp: false);
        $this->submitLogin();

        $this->browser->request('GET', self::authorizeUrl('', ['code_challenge' => null, 'code_challenge_method' => null]));
        self::assertResponseStatusCodeSame(400);

        $plain = str_repeat('a', 50);
        $this->browser->request('GET', self::authorizeUrl($plain, ['code_challenge_method' => 'plain']));
        self::assertResponseStatusCodeSame(400);
    }

    public function testTokenEndpointRequiresAValidDpopProof(): void
    {
        [$status, $error] = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => 'x'], null);
        self::assertSame(400, $status);
        self::assertSame('invalid_dpop_proof', $error['error']);

        // A proof for another endpoint is rejected (htu mismatch).
        $this->browser->request('POST', '/token', ['grant_type' => 'authorization_code', 'code' => 'x', 'client_id' => self::CLIENT_ID], server: [
            'HTTP_DPOP' => DpopProofFactory::withNewKey()->create('POST', 'https://elsewhere.test/token'),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_dpop_proof', json_decode((string) $this->browser->getResponse()->getContent(), true)['error']);
    }

    public function testCorsPreflightOnTokenEndpoint(): void
    {
        $this->browser->request('OPTIONS', '/token', server: ['HTTP_ORIGIN' => 'http://spa.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', 'http://spa.test');
        self::assertStringContainsString('DPoP', (string) $this->browser->getResponse()->headers->get('Access-Control-Allow-Headers'));

        $this->browser->request('OPTIONS', '/token', server: ['HTTP_ORIGIN' => 'http://evil.test']);
        self::assertResponseHeaderNotSame('Access-Control-Allow-Origin', 'http://evil.test');
    }

    public function testLoginEventsAreSentAsSignedWebhooks(): void
    {
        [, $secret] = $this->createUser();
        $this->obtainAuthorizationCode(self::pkce()['challenge'], $secret);

        $events = array_column(self::webhooks(), 'type');
        self::assertSame([WebhookEvent::USER_TWO_FACTOR_ENABLED, WebhookEvent::USER_LOGIN_SUCCEEDED], $events);
        self::assertTrue(self::webhooks()[1]['data']['second_factor']);

        $request = RecordingHttpClient::$requests[array_key_last(RecordingHttpClient::$requests)];
        self::assertSame('http://webhook.test/receive', $request['url']);
        $signatureHeader = null;
        foreach ($request['options']['headers'] as $name => $value) {
            if (0 === strcasecmp($name, WebhookSignature::HEADER)) {
                $signatureHeader = $value;
            }
        }
        $signature = new WebhookSignature('test-webhook-secret-0123456789abcdef');
        self::assertTrue($signature->verify($request['options']['body'], $signatureHeader));
        self::assertSame('app-1001', WebhookEvent::fromJson($request['options']['body'])->userId());
    }

    public function testDiscoveryDocuments(): void
    {
        $this->browser->request('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        $metadata = json_decode((string) $this->browser->getResponse()->getContent(), true);
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertContains('ES256', $metadata['dpop_signing_alg_values_supported']);

        $this->browser->request('GET', '/.well-known/jwks.json');
        $jwks = json_decode((string) $this->browser->getResponse()->getContent(), true);
        self::assertSame('RSA', $jwks['keys'][0]['kty']);
        self::assertArrayNotHasKey('d', $jwks['keys'][0], 'never publish the private key');
    }
}
