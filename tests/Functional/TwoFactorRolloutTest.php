<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\TwoFactor\TwoFactorPolicy;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;

/**
 * Roll-out of 2FA to existing accounts: grace period with a configurable deadline, optional mode,
 * import of existing accounts with their password hashes.
 */
final class TwoFactorRolloutTest extends OAuthTestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if (null === $value) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    public function testGracePeriodLetsUsersPostponeOncePerLogin(): void
    {
        $this->policy(TwoFactorPolicy::MODE_GRACE, '');
        $this->createUser(withTotp: false);

        $crawler = $this->loginUntilEnrollmentPage();
        self::assertSelectorTextContains('.sub', 'will soon be required');

        // "Later": the authorization goes on.
        $this->browser->submit($crawler->selectButton('Later')->form());
        $this->browser->followRedirect();
        $this->codeFromRedirect();
        self::assertSame(1, $this->alice()->getTwoFactorPostponeCount());
        self::assertContains(WebhookEvent::USER_TWO_FACTOR_POSTPONED, array_column(self::webhooks(), 'type'));

        // Same login session: not asked again.
        $this->browser->request('GET', self::authorizeUrl(self::pkce()['challenge']));
        $this->codeFromRedirect();

        // Next login: asked again.
        $this->browser->request('GET', '/logout');
        $crawler = $this->loginUntilEnrollmentPage();
        $this->browser->submit($crawler->selectButton('Later')->form());
        self::assertSame(2, $this->alice()->getTwoFactorPostponeCount());
    }

    public function testDeadlineIsShownThenEnforced(): void
    {
        $this->policy(TwoFactorPolicy::MODE_GRACE, (new \DateTimeImmutable('+10 days'))->format('Y-m-d'));
        $this->createUser(withTotp: false);

        $this->loginUntilEnrollmentPage();
        self::assertSelectorTextContains('.sub', 'required from');
        self::assertSelectorTextContains('.sub', 'days left');
        self::assertSelectorExists('button.secondary');

        $this->policy(TwoFactorPolicy::MODE_GRACE, (new \DateTimeImmutable('-1 day'))->format('Y-m-d'));
        $this->browser->request('GET', '/logout');
        $this->loginUntilEnrollmentPage();
        self::assertSelectorTextContains('.sub', 'is required for your account');
        self::assertSelectorNotExists('button.secondary', 'no "Later" once the deadline has passed');
    }

    public function testAdministratorsCannotPostpone(): void
    {
        $this->policy(TwoFactorPolicy::MODE_GRACE, '');
        [$admin] = $this->createUser('alice', 'app-1001', withTotp: false);
        $admin->addRole(User::ROLE_ADMIN);
        $this->em()->flush();

        $this->loginUntilEnrollmentPage();
        self::assertSelectorNotExists('button.secondary');
    }

    public function testOptionalModeDoesNotAskAndAllowsDisabling(): void
    {
        $this->policy(TwoFactorPolicy::MODE_OPTIONAL, '');
        $this->createUser(withTotp: false);
        [, $secret] = $this->createUser('bob', 'app-1002');

        $this->browser->request('GET', self::authorizeUrl(self::pkce()['challenge']));
        $this->submitLogin();
        $this->browser->followRedirect();
        $this->codeFromRedirect();

        $this->browser->request('GET', '/logout');
        $this->submitLogin('bob');
        $this->submitTotp(self::totp($secret));
        $this->browser->request('GET', '/account');
        self::assertSelectorExists('form[action="/account/2fa/disable"]');
    }

    public function testImportKeepsPasswordHashesAndUpgradesThemAtLogin(): void
    {
        $this->createUser('existing', 'app-1');
        $csv = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($csv, implode("\n", [
            'username,external_user_id,password_hash',
            'alice,app-1001,'.password_hash(self::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]),
            'bob,app-1002,'.password_hash('another password 123', \PASSWORD_ARGON2ID),
            'existing,app-1,'.password_hash('x', \PASSWORD_BCRYPT, ['cost' => 4]),
            'carol,app-1003,plain-text-password',
            '',
        ]));

        $tester = $this->command('app:user:import');
        $tester->execute(['file' => $csv]);
        self::assertStringContainsString('2 account(s) created, 1 skipped (already present), 1 invalid', $tester->getDisplay());

        $tester->execute(['file' => $csv]);
        self::assertStringContainsString('0 account(s) created, 3 skipped', $tester->getDisplay(), 'idempotent');
        unlink($csv);

        self::assertStringStartsWith('$2y$', $this->alice()->getPassword());

        // The Laravel bcrypt hash works as is, and is rehashed to Argon2id at the first login.
        $this->submitLogin();
        $this->em()->clear();
        self::assertStringStartsWith('$argon2id$', $this->alice()->getPassword());
    }

    public function testStatusCommand(): void
    {
        $this->policy(TwoFactorPolicy::MODE_GRACE, '2099-01-01');
        $this->createUser('alice', 'app-1001');
        $this->createUser('bob', 'app-1002', withTotp: false);

        $tester = $this->command('app:2fa:status');
        $tester->execute(['--pending' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('grace', $display);
        self::assertStringContainsString('2099-01-01', $display);
        self::assertStringContainsString('1 (50 %)', $display);
        self::assertStringContainsString('bob', $display);
    }

    private function policy(string $mode, string $deadline): void
    {
        foreach (['APP_2FA_MODE' => $mode, 'APP_2FA_DEADLINE' => $deadline] as $name => $value) {
            if (!\array_key_exists($name, $this->savedEnv)) {
                $this->savedEnv[$name] = \is_string($_ENV[$name] ?? null) ? $_ENV[$name] : null;
            }
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        // Env vars are resolved when a container is built: start from a fresh kernel.
        self::ensureKernelShutdown();
        $this->browser = static::createClient();
    }

    private function loginUntilEnrollmentPage(): \Symfony\Component\DomCrawler\Crawler
    {
        $this->browser->request('GET', self::authorizeUrl(self::pkce()['challenge']));
        $this->submitLogin();
        $this->browser->followRedirect(); // /authorize
        self::assertStringStartsWith('/account/2fa/setup', (string) $this->browser->getResponse()->headers->get('Location'));

        return $this->browser->followRedirect();
    }

    private function alice(): User
    {
        $this->em()->clear();

        return $this->userNamed('alice');
    }
}
