<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * First page after sign-in (App\Controller\DashboardController).
 */
final class DashboardControllerTest extends AdminTestCase
{
    public function testSignInLandsOnTheDashboard(): void
    {
        $this->browser->request('GET', '/');
        self::assertResponseRedirects('/login');

        $this->signIn('reader');
        $this->browser->request('GET', '/');
        self::assertResponseRedirects('/dashboard');
    }

    public function testRegularUserSeesNotificationsOnly(): void
    {
        $this->signIn('reader');
        $this->browser->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice.warn', 'Two-factor authentication is not enabled');
        self::assertSelectorExists('.notice a[href="/account/2fa/setup"]');
        self::assertSelectorNotExists('.cards');
        self::assertSelectorNotExists('nav a[href="/admin/users"]');
    }

    public function testManagerSeesFiguresOfHisDomainsOnly(): void
    {
        $this->signIn('reader');
        $this->signIn('manager');
        $crawler = $this->browser->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'the users of the domains you manage');
        self::assertSame('4', trim($crawler->filter('.card .value')->first()->text()), 'owner, manager, reader, dealer');
        self::assertStringContainsString('0 % with two-factor (0 / 4)', $crawler->filter('.cards')->text());
        $logins = $crawler->filter('section')->first()->text();
        self::assertStringContainsString('reader', $logins);
        self::assertStringContainsString('manager', $logins);
        self::assertStringNotContainsString('south', $crawler->filter('main')->text(), 'nothing from the sibling domain');
        self::assertSelectorExists('nav a[href="/admin/users"]');
    }

    public function testPlatformStaffSeesEverybody(): void
    {
        $this->signIn('support');
        $crawler = $this->browser->request('GET', '/dashboard');

        self::assertSelectorTextContains('h2', 'all users');
        self::assertSame('7', trim($crawler->filter('.card .value')->first()->text()));
        self::assertSelectorTextContains('.notice.warn', 'Administrators need it');
        self::assertStringContainsString('/a1/a11/', $crawler->filter('section')->last()->text(), 'last domains created');
    }
}
