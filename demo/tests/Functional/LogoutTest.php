<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Logout, and the return to the application that asked for it (App\Security\LogoutRedirectListener).
 */
final class LogoutTest extends OAuthTestCase
{
    public function testLogoutClosesTheSessionAndShowsTheLoginPage(): void
    {
        $this->signIn();

        $this->browser->request('GET', '/logout');
        self::assertResponseRedirects('http://localhost/login');

        $this->assertSignedOut();
    }

    public function testApplicationGetsTheBrowserBackAfterLogout(): void
    {
        $this->signIn();

        $this->browser->request('GET', '/logout?'.http_build_query([
            'client_id' => self::CLIENT_ID,
            'post_logout_redirect_uri' => 'http://spa.test/signed-out?x=1',
        ]));
        self::assertResponseRedirects('http://spa.test/signed-out?x=1');

        $this->assertSignedOut();
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function refusedReturns(): iterable
    {
        yield 'other origin' => [['client_id' => self::CLIENT_ID, 'post_logout_redirect_uri' => 'https://evil.test/']];
        yield 'same host, other port' => [['client_id' => self::CLIENT_ID, 'post_logout_redirect_uri' => 'http://spa.test:8080/']];
        yield 'same host, other scheme' => [['client_id' => self::CLIENT_ID, 'post_logout_redirect_uri' => 'https://spa.test/']];
        yield 'credentials trick' => [['client_id' => self::CLIENT_ID, 'post_logout_redirect_uri' => 'http://spa.test@evil.test/']];
        yield 'relative URL' => [['client_id' => self::CLIENT_ID, 'post_logout_redirect_uri' => '//evil.test/']];
        yield 'javascript URL' => [['client_id' => self::CLIENT_ID, 'post_logout_redirect_uri' => 'javascript:alert(1)']];
        yield 'unknown client' => [['client_id' => 'nobody', 'post_logout_redirect_uri' => 'http://spa.test/']];
        yield 'no client' => [['post_logout_redirect_uri' => 'http://spa.test/']];
    }

    /**
     * @param array<string, string> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedReturns')]
    public function testUnregisteredReturnAddressesAreIgnored(array $query): void
    {
        $this->signIn();

        $this->browser->request('GET', '/logout?'.http_build_query($query));
        self::assertResponseRedirects('http://localhost/login');

        $this->assertSignedOut();
    }

    public function testMetadataAdvertisesTheLogoutEndpoint(): void
    {
        $this->browser->request('GET', '/.well-known/oauth-authorization-server');

        self::assertSame('http://localhost/logout', $this->responseJson()['end_session_endpoint'] ?? null);
    }

    private function signIn(): void
    {
        $this->createUser(withTotp: false);
        $this->submitLogin();
        $this->browser->request('GET', '/account');
        self::assertResponseIsSuccessful();
    }

    private function assertSignedOut(): void
    {
        $this->browser->request('GET', '/account');
        self::assertResponseRedirects('http://localhost/login');
    }
}
