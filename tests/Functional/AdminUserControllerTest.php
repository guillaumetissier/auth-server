<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccessGrant;
use App\Entity\User;
use App\Event\AccountEvent;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Symfony\Component\Mime\Email;

/**
 * Administration of accounts (App\Controller\AdminUserController).
 */
final class AdminUserControllerTest extends AdminTestCase
{
    public function testDomainManagerCreatesAUserWhoReceivesAnInvitation(): void
    {
        $this->signIn('manager');
        $this->browser->request('GET', '/admin/users/new?domain=a100');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select#domain option[value="a100"][selected]');
        self::assertSelectorNotExists('select#domain option[value="a11"]', 'only the domains he manages');
        self::assertSelectorNotExists('select#domain option[value=""]', 'only the super-administrator creates users without domain');

        $this->browser->submitForm('Create and send the invitation', [
            'username' => 'newbie',
            'external_user_id' => 'app-newbie',
            'email' => 'Newbie@Example.test',
            'domain' => 'a100',
        ]);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'newbie@example.test');
        self::assertSame('Your new account', $email->getSubject());
        self::assertMatchesRegularExpression('#http://localhost/password/set/[A-Za-z0-9_-]{40,}#', (string) $email->getTextBody());

        $newbie = $this->userNamed('newbie');
        self::assertSame('a100', $newbie->getDomain()?->getCode());
        self::assertSame('newbie@example.test', $newbie->getEmail(), 'stored in lower case');
        self::assertTrue($newbie->hasValidPasswordLink(new \DateTimeImmutable('+6 days')), 'invitations last 7 days');
        self::assertResponseRedirects('/admin/users/'.$newbie->getId());
        self::assertContains(WebhookEvent::USER_CREATED, array_column(self::webhooks(), 'type'));
    }

    public function testCreationIsRefusedOutsideTheManagedDomainsAndForDuplicates(): void
    {
        $this->signIn('manager');
        $this->browser->request('GET', '/admin/users/new');
        $token = (string) $this->browser->getCrawler()->filter('input[name=_csrf_token]')->attr('value');

        $cases = [
            [['domain' => 'a11'], 'You cannot create users in this domain.'],
            [['domain' => ''], 'Choose the domain of the user.'],
            [['username' => 'reader'], 'The username "reader" is already taken.'],
            [['email' => 'reader@example.test'], 'already used by another account'],
            [['external_user_id' => 'app-reader'], 'already linked to another account'],
            [['username' => 'with space'], 'no spaces'],
            [['email' => 'not-an-email'], 'not a valid e-mail address'],
        ];
        foreach ($cases as [$override, $message]) {
            $this->browser->request('POST', '/admin/users/new', $override + [
                'username' => 'other', 'external_user_id' => 'app-other', 'email' => 'other@example.test', 'domain' => 'a10', '_csrf_token' => $token,
            ]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.alert.error', $message);
        }
        self::assertEmailCount(0);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'other']));
    }

    public function testSuperAdministratorCreatesPlatformStaffWithoutDomain(): void
    {
        $this->signIn('root');
        $this->browser->request('GET', '/admin/users/new');
        $this->browser->submitForm('Create and send the invitation', [
            'username' => 'helpdesk', 'external_user_id' => 'app-helpdesk', 'email' => 'helpdesk@example.test', 'domain' => '',
        ]);

        self::assertEmailCount(1);
        self::assertNull($this->userNamed('helpdesk')->getDomain());
    }

    public function testPlatformAdministratorOnlyLooks(): void
    {
        $this->signIn('support');
        $this->browser->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/users/new"]');
        self::assertSelectorTextContains('table', 'south', 'sees every domain');

        $reader = $this->userNamed('reader');
        $this->browser->request('GET', '/admin/users/'.$reader->getId());
        self::assertSelectorTextContains('body', 'Platform administrators see the accounts but do not change them');
        self::assertSelectorNotExists('form[action$="/delete"]');

        $this->browser->request('GET', '/admin/users/new');
        self::assertResponseStatusCodeSame(403);
        $policy = static::getContainer()->get(\App\Access\AccessPolicy::class);
        self::assertNotNull($policy->accountChangeDenial($this->userNamed('support'), $this->userNamed('reader')));
    }

    public function testManagerEditsTheIdentityOfAUserOfHisDomain(): void
    {
        $dealer = $this->userNamed('dealer');
        $this->signIn('manager');
        $this->browser->request('GET', '/admin/users/'.$dealer->getId());
        $this->submitForm('/admin/users/'.$dealer->getId().'/identity', ['username' => 'dealer.one', 'email' => 'one@example.test']);
        self::assertResponseRedirects('/admin/users/'.$dealer->getId());

        $dealer = $this->reload($dealer);
        self::assertSame('dealer.one', $dealer->getUsername());
        self::assertSame('one@example.test', $dealer->getEmail());
        self::assertContains(AccountEvent::USER_UPDATED, array_column(self::webhooks(), 'type'));
    }

    public function testManagerCannotManageAccountsAboveOrBesideHim(): void
    {
        $this->signIn('manager');
        foreach (['owner' => 'owner of your domain', 'south' => 'not allowed to manage this account', 'support' => 'Only the super-administrator', 'root' => 'super-administrator account', 'manager' => 'your own account'] as $name => $message) {
            $target = $this->userNamed($name);
            $this->browser->request('GET', '/admin/users/'.$target->getId());
            if (200 !== $this->browser->getResponse()->getStatusCode()) {
                self::assertResponseStatusCodeSame(403, $name.': page not even visible');
                continue;
            }
            self::assertSelectorTextContains('body', $message, $name);
            $this->browser->request('POST', '/admin/users/'.$target->getId().'/delete', ['confirm' => $name, '_csrf_token' => $this->csrf('admin_user')]);
            self::assertResponseStatusCodeSame(403, $name);
        }
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'owner']));
    }

    public function testPasswordLinkSentByAnAdministrator(): void
    {
        $reader = $this->userNamed('reader');
        $this->signIn('manager');
        $this->browser->request('GET', '/admin/users/'.$reader->getId());
        $this->submitForm('/admin/users/'.$reader->getId().'/password-link');

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Choose a new password', $email->getSubject());
        self::assertEmailAddressContains($email, 'To', 'reader@example.test');
        self::assertTrue($this->reload($reader)->hasValidPasswordLink(new \DateTimeImmutable()));
        self::assertContains(AccountEvent::USER_PASSWORD_LINK_SENT, array_column(self::webhooks(), 'type'));
    }

    public function testDisablingEndsTheSessionAndBlocksSignIn(): void
    {
        $reader = $this->userNamed('reader');

        // reader is signed in in another browser
        $this->signIn('reader');
        $readerCookies = $this->browser->getCookieJar()->all();

        $this->signIn('manager');
        $this->browser->request('GET', '/admin/users/'.$reader->getId());
        $this->submitForm('/admin/users/'.$reader->getId().'/status');
        self::assertFalse($this->reload($reader)->isEnabled());
        self::assertContains(AccountEvent::USER_DISABLED, array_column(self::webhooks(), 'type'));

        $this->browser->getCookieJar()->clear();
        foreach ($readerCookies as $cookie) {
            $this->browser->getCookieJar()->set($cookie);
        }
        $this->browser->request('GET', '/dashboard');
        self::assertResponseRedirects('http://localhost/login', null, 'the open session ends');

        $this->submitLogin('reader');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'This account is disabled.');

        $this->signIn('manager');
        $this->browser->request('GET', '/admin/users/'.$reader->getId());
        $this->submitForm('/admin/users/'.$reader->getId().'/status');
        self::assertTrue($this->reload($reader)->isEnabled());
        $this->signIn('reader');
    }

    public function testTwoFactorResetForALostPhone(): void
    {
        $dealer = $this->userNamed('dealer');
        $enrollment = static::getContainer()->get(\App\TwoFactor\TotpEnrollment::class);
        $enrollment->enable($dealer, $enrollment->generateSecret());
        $this->em()->clear();

        $this->signIn('manager');
        $this->browser->request('GET', '/admin/users/'.$dealer->getId());
        self::assertSelectorTextContains('body', 'enabled since');
        $this->submitForm('/admin/users/'.$dealer->getId().'/two-factor/reset');

        self::assertNull($this->reload($dealer)->getTotpEnabledAt());
        self::assertContains(WebhookEvent::USER_TWO_FACTOR_DISABLED, array_column(self::webhooks(), 'type'));
    }

    public function testDeletionNeedsTheUsernameAndRemovesEverything(): void
    {
        $reader = $this->userNamed('reader');
        $id = $reader->getId();
        $this->signIn('manager');
        $this->browser->request('GET', '/admin/users/'.$id);
        $this->submitForm('/admin/users/'.$id.'/delete', ['confirm' => 'wrong']);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'Type the username (reader)');

        $this->submitForm('/admin/users/'.$id.'/delete', ['confirm' => 'reader']);
        self::assertResponseRedirects('/admin/users');

        $this->em()->clear();
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'reader']));
        self::assertSame(0, $this->em()->getRepository(AccessGrant::class)->count(['user' => $id]));
        self::assertContains(AccountEvent::USER_DELETED, array_column(self::webhooks(), 'type'));
    }

    public function testUsersListShowsStatusAndTwoFactor(): void
    {
        $this->signIn('manager');
        $crawler = $this->browser->request('GET', '/admin/users?q=example.test');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr')->each(static fn ($tr): string => $tr->text());
        self::assertCount(4, $rows, 'owner, manager, reader, dealer: the domains he manages, found by e-mail');
        self::assertStringContainsString('off', $rows[0]);
    }

    /**
     * A valid CSRF token of the current session, taken from a page the manager can change.
     */
    private function csrf(string $id): string
    {
        $this->browser->request('GET', '/admin/users/'.$this->userNamed('dealer')->getId());
        $token = $this->browser->getCrawler()->filter('form[action$="/delete"] input[name=_csrf_token]')->attr('value');
        self::assertNotNull($token, 'CSRF token for '.$id);

        return $token;
    }
}
