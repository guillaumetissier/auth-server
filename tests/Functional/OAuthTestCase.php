<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\RecordingHttpClient;
use App\TwoFactor\TotpEnrollment;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use GuillaumeTissier\ResourceGuard\Client\DpopProofFactory;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class OAuthTestCase extends WebTestCase
{
    protected const CLIENT_ID = 'spa';
    protected const REDIRECT_URI = 'http://spa.test/callback';
    protected const TOKEN_URL = 'http://localhost/token';
    protected const PASSWORD = 'correct horse battery staple';

    protected KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        RecordingHttpClient::$requests = [];

        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Public SPA client: no secret, PKCE + DPoP.
        $client = new Client('SPA', self::CLIENT_ID, null);
        $client->setRedirectUris(new RedirectUri(self::REDIRECT_URI));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope('profile'), new Scope('offline_access'), new Scope('items.read'), new Scope('items.write'));
        static::getContainer()->get(ClientManagerInterface::class)->save($client);
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{User, string|null} the user and its plain TOTP secret
     */
    protected function createUser(string $username = 'alice', string $externalId = 'app-1001', bool $withTotp = true): array
    {
        $container = static::getContainer();
        $user = new User($username, $externalId);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);
        $this->em()->flush();

        $secret = null;
        if ($withTotp) {
            $enrollment = $container->get(TotpEnrollment::class);
            $secret = $enrollment->generateSecret();
            $enrollment->enable($user, $secret);
        }

        return [$user, $secret];
    }

    protected static function totp(string $secret): string
    {
        return TOTP::createFromSecret($secret)->now();
    }

    /**
     * @return array{verifier: string, challenge: string}
     */
    protected static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    protected static function authorizeUrl(string $challenge, array $overrides = []): string
    {
        return '/authorize?'.http_build_query($overrides + [
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'profile offline_access',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    protected function submitLogin(string $username = 'alice', string $password = self::PASSWORD, string $ip = '10.0.0.1'): void
    {
        $crawler = $this->browser->request('GET', '/login', server: ['REMOTE_ADDR' => $ip]);
        $form = $crawler->selectButton('Continue')->form(['_username' => $username, '_password' => $password]);
        $this->browser->submit($form, [], ['REMOTE_ADDR' => $ip]);
    }

    protected function submitTotp(string $code): void
    {
        $crawler = $this->browser->request('GET', '/2fa');
        $this->browser->submit($crawler->selectButton('Verify')->form(['_auth_code' => $code]));
    }

    /**
     * Runs the whole browser part of the flow and returns the authorization code.
     */
    protected function obtainAuthorizationCode(string $challenge, string $totpSecret): string
    {
        $this->browser->request('GET', self::authorizeUrl($challenge));
        self::assertResponseRedirects('http://localhost/login');

        $this->submitLogin();
        $this->browser->followRedirect(); // back to /authorize, where the pending 2FA is detected
        self::assertResponseRedirects('http://localhost/2fa');

        $this->submitTotp(self::totp($totpSecret));
        self::assertResponseRedirects();
        $this->browser->followRedirect(); // back to /authorize

        return $this->codeFromRedirect();
    }

    protected function codeFromRedirect(): string
    {
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('xyz', $query['state'] ?? null);

        return (string) $query['code'];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{int, array<string, mixed>}
     */
    protected function tokenRequest(array $params, ?DpopProofFactory $dpop): array
    {
        $server = ['HTTP_ORIGIN' => 'http://spa.test'];
        if (null !== $dpop) {
            $server['HTTP_DPOP'] = $dpop->create('POST', self::TOKEN_URL);
        }

        $this->browser->request('POST', '/token', $params + ['client_id' => self::CLIENT_ID], server: $server);
        $response = $this->browser->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function jwtClaims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    /**
     * @return list<array<string, mixed>> decoded webhook payloads sent so far
     */
    protected static function webhooks(): array
    {
        return array_map(static fn (array $r): array => json_decode($r['options']['body'], true), RecordingHttpClient::$requests);
    }
}
