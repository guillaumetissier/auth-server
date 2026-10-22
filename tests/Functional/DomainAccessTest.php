<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\AccessManager;
use App\Entity\ClientPolicy;
use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use GuillaumeTissier\ResourceGuard\Client\DpopProofFactory;
use GuillaumeTissier\ResourceGuard\Dpop\DpopProofValidator;
use GuillaumeTissier\ResourceGuard\Dpop\InMemoryReplayStore;
use GuillaumeTissier\ResourceGuard\Guard\Principal;
use GuillaumeTissier\ResourceGuard\Guard\ResourceGuard;
use GuillaumeTissier\ResourceGuard\Token\AccessTokenValidator;
use GuillaumeTissier\ResourceGuard\Token\Impersonation;
use GuillaumeTissier\ResourceGuard\Token\StaticJwksProvider;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use League\Bundle\OAuth2ServerBundle\Model\Client;

/**
 * Domain tree: a1 > a10 > (a100, a101) and a1 > a11.
 *  - alice: member and owner of a10 (owner rights "*:m @access:w @domain:w", no impersonation);
 *  - bob:   member of a10, grant "*:r vehicle:w" on a10 and subdomains;
 *  - carla: member of a11 (sibling);
 *  - dave:  member of a100.
 */
final class DomainAccessTest extends OAuthTestCase
{
    private const API = 'https://api.test/api/items';

    /** @var array<string, array{User, string|null}> */
    private array $people = [];
    /** @var array<string, Domain> */
    private array $tree = [];

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        foreach (['vehicle' => 'Vehicles', 'tire' => 'Tires', 'invoice' => 'Invoices', 'user' => 'User profiles'] as $code => $label) {
            $em->persist(new ResourceType($code, $label));
        }
        $em->flush();

        $manager = $this->manager();
        $this->tree['a1'] = $manager->createDomain('a1', 'Group', null);
        $this->tree['a10'] = $manager->createDomain('a10', 'Region 10', $this->tree['a1']);
        $this->tree['a100'] = $manager->createDomain('a100', 'Dealer 100', $this->tree['a10']);
        $this->tree['a101'] = $manager->createDomain('a101', 'Dealer 101', $this->tree['a10']);
        $this->tree['a11'] = $manager->createDomain('a11', 'Region 11', $this->tree['a1']);

        foreach (['alice' => 'a10', 'bob' => 'a10', 'carla' => 'a11', 'dave' => 'a100'] as $name => $domain) {
            $this->people[$name] = $this->createUser($name, 'app-'.$name);
            $manager->moveUser($this->people[$name][0], $this->tree[$domain], null);
        }
        $manager->setOwner($this->tree['a10'], $this->user('alice'), AccessExpression::parse(Domain::DEFAULT_OWNER_EXPRESSION), null);
        $manager->grant($this->user('bob'), $this->tree['a10'], AccessExpression::parse('*:r vehicle:w'), true, null);
    }

    public function testTokenCarriesTheDomainAndTheRights(): void
    {
        [$tokens, $key] = $this->tokensFor('bob');
        $claims = self::jwtClaims(self::stringAt($tokens, 'access_token'));

        self::assertSame('/a1/a10/', $claims['dom']);
        self::assertContains(['/a1/a10/', true, '*:r vehicle:w'], self::arrayAt($claims, 'acl'));
        self::assertContains(['/a1/a10/', true, 'user:w:own'], self::arrayAt($claims, 'acl'), 'ACCESS_DEFAULT_EXPRESSION');

        $bob = $this->principal($tokens, $key);
        self::assertTrue($bob->can('vehicle', AccessLevel::Write, '/a1/a10/a101/'), 'inherited by subdomains');
        self::assertTrue($bob->can('invoice', AccessLevel::Read, '/a1/a10/a100/'));
        self::assertFalse($bob->can('invoice', AccessLevel::Write, '/a1/a10/'));
        self::assertFalse($bob->can('vehicle', AccessLevel::Read, '/a1/'), 'never the parent domain');
        self::assertFalse($bob->can('vehicle', AccessLevel::Read, '/a1/a11/'), 'never a sibling domain');
        self::assertTrue($bob->can('user', AccessLevel::Write, '/a1/a10/', ownerId: 'app-bob'), 'his own profile');
        self::assertFalse($bob->can('user', AccessLevel::Write, '/a1/a10/', ownerId: 'app-alice'), 'not the others\'');
    }

    public function testOwnerRightsApplyToTheWholeSubtree(): void
    {
        [$tokens, $key] = $this->tokensFor('alice');
        $alice = $this->principal($tokens, $key);

        self::assertTrue($alice->can('invoice', AccessLevel::Manage, '/a1/a10/a101/'));
        self::assertFalse($alice->can('invoice', AccessLevel::Read, '/a1/a11/'));
    }

    public function testDomainOwnerCannotImpersonateUntilAllowed(): void
    {
        $pkce = self::pkce();
        $this->loginThroughAuthorize('alice', self::authorizeUrl($pkce['challenge'], ['impersonate' => 'app-bob']));
        $this->assertAccessDenied();

        // Enabled in the owner rights of her domain, read-only.
        $this->manager()->setOwner($this->domain('a10'), $this->user('alice'), AccessExpression::parse(Domain::DEFAULT_OWNER_EXPRESSION.' @impersonate:r'), null);

        $pkce = self::pkce();
        $key = DpopProofFactory::withNewKey();
        $this->browser->request('GET', self::authorizeUrl($pkce['challenge'], ['impersonate' => 'app-bob']));
        self::assertStringStartsWith('/impersonation/confirm', (string) $this->browser->getResponse()->headers->get('Location'));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.sub', 'read-only');
        $this->confirm('alice');

        [$status, $tokens] = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $this->codeFromRedirect(), 'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $pkce['verifier']], $key);
        self::assertSame(200, $status, (string) json_encode($tokens));
        $claims = self::jwtClaims(self::stringAt($tokens, 'access_token'));
        self::assertSame('app-bob', $claims['sub']);
        self::assertSame(['sub' => 'app-alice'], $claims['act']);
        self::assertSame(Impersonation::MODE_READ_ONLY, $claims['imp_mode']);
        self::assertSame('/a1/a10/', $claims['dom'], 'the impersonated user\'s rights');

        $asBob = $this->principal($tokens, $key);
        self::assertSame(AccessLevel::Read, $asBob->accessLevel('vehicle', '/a1/a10/'), 'capped at read');
    }

    public function testImpersonationStaysInsideTheDomain(): void
    {
        $this->manager()->setOwner($this->domain('a10'), $this->user('alice'), AccessExpression::parse('*:m @impersonate:w'), null);

        $pkce = self::pkce();
        $this->loginThroughAuthorize('alice', self::authorizeUrl($pkce['challenge'], ['impersonate' => 'app-carla']));
        $this->assertAccessDenied();
    }

    public function testDelegateCannotImpersonateTheOwnerOfHisDomain(): void
    {
        $this->manager()->grant($this->user('bob'), $this->domain('a10'), AccessExpression::parse('*:r @impersonate:w'), true, null);

        $pkce = self::pkce();
        $this->loginThroughAuthorize('bob', self::authorizeUrl($pkce['challenge'], ['impersonate' => 'app-alice']));
        $this->assertAccessDenied();

        // dave (a100) is fine, with full rights.
        $pkce = self::pkce();
        $this->browser->request('GET', self::authorizeUrl($pkce['challenge'], ['impersonate' => 'app-dave']));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.sub', 'all the rights');
    }

    public function testOwnerManagesRightsWithinHisOwn(): void
    {
        $this->loginAs('alice');

        $crawler = $this->browser->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $listed = $crawler->filter('tbody td:first-child a')->each(static fn ($a): string => $a->text());
        self::assertSame(['a10', 'a100', 'a101'], $listed, 'only her subtree');

        // Granting within her rights works and is notified.
        $this->grant('alice', 'dave', 'a100', 'tire:w invoice:r');
        self::assertSelectorExists('.alert.ok');
        self::assertSame('invoice:r tire:w', $this->manager()->findGrant($this->user('dave'), $this->domain('a100'))?->getExpression()->toString());
        $hook = $this->webhookOf(WebhookEvent::USER_ACCESS_CHANGED);
        self::assertSame('app-dave', self::arrayAt($hook, 'data')['user_id'] ?? null);
        self::assertSame('app-alice', self::arrayAt($hook, 'data')['actor_id'] ?? null);

        // Beyond her rights: a power she does not hold.
        $this->grant('alice', 'dave', 'a100', 'tire:w @impersonate:r');
        self::assertSelectorTextContains('.alert.error', 'exceed');

        // Outside the user's domain: dave belongs to a100.
        $this->grant('alice', 'dave', 'a10', 'tire:r');
        self::assertSelectorTextContains('.alert.error', 'belongs to domain "a100"');

        // Unknown type.
        $this->grant('alice', 'dave', 'a100', 'tyre:r');
        self::assertSelectorTextContains('.alert.error', 'Unknown resource type');

        // Her own rights, and users of another domain, are out of reach.
        $this->browser->request('GET', '/admin/users/'.$this->user('carla')->getId());
        self::assertResponseStatusCodeSame(403);
        $this->grant('alice', 'alice', 'a10', 'tire:r');
        self::assertSelectorTextContains('.alert.error', 'your own rights');
    }

    public function testDelegateCannotRaiseRightsAboveHisOwn(): void
    {
        $this->manager()->grant($this->user('bob'), $this->domain('a10'), AccessExpression::parse('*:w tire:r @access:w'), true, null);
        $this->loginAs('bob');

        $this->grant('bob', 'dave', 'a100', '*:w');
        self::assertSelectorTextContains('.alert.error', 'exceed', 'tire is capped at read for bob, "*:w" would give dave tire:w');

        $this->grant('bob', 'dave', 'a100', '*:w tire:r');
        self::assertSelectorExists('.alert.ok');

        $this->browser->request('GET', '/admin/users/'.$this->user('alice')->getId());
        self::assertSelectorTextContains('.help', 'owner of your domain');
    }

    public function testUserWithoutAdministrationRightsIsRefused(): void
    {
        $this->loginAs('dave');
        $this->browser->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testImpersonateButtonUsesTheClientEntryPoint(): void
    {
        $em = $this->em();
        $client = $em->find(Client::class, self::CLIENT_ID);
        self::assertNotNull($client);
        $policy = new ClientPolicy($client);
        $policy->setImpersonationStartUri('http://spa.test/impersonate?user={user}');
        $em->persist($policy);
        $em->flush();
        $this->manager()->setOwner($this->domain('a10'), $this->user('alice'), AccessExpression::parse(Domain::DEFAULT_OWNER_EXPRESSION.' @impersonate:r'), null);

        $this->loginAs('alice');
        $crawler = $this->browser->request('GET', '/admin/users/'.$this->user('bob')->getId());
        self::assertSame('http://spa.test/impersonate?user=app-bob', $crawler->selectLink('Impersonate in SPA')->attr('href'));
        self::assertSelectorTextContains('main', 'read-only');

        // Preview of the effective rights.
        self::assertSelectorTextContains('table:last-of-type', 'vehicle');
    }

    public function testImportIsCheckedBeforeWriting(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($file, json_encode([
            'domains' => [['code' => 'b1', 'name' => 'B1', 'parent' => null]],
            'grants' => [['user' => 'carla', 'domain' => 'a10', 'rights' => 'vehicle:r'], ['user' => 'dave', 'domain' => 'a100', 'rights' => 'boat:w']],
        ]));

        $tester = $this->command('app:access:import');
        $status = $tester->execute(['file' => $file]);
        unlink($file);

        self::assertSame(1, $status);
        self::assertStringContainsString('carla belongs to /a1/a11/', $tester->getDisplay());
        self::assertStringContainsString('unknown resource type "boat"', $tester->getDisplay());
        self::assertNull($this->em()->getRepository(Domain::class)->findOneBy(['code' => 'b1']), 'nothing written');
    }

    private function manager(): AccessManager
    {
        return static::getContainer()->get(AccessManager::class);
    }

    private function domain(string $code): Domain
    {
        $domain = $this->em()->getRepository(Domain::class)->findOneBy(['code' => $code]);
        self::assertNotNull($domain);

        return $domain;
    }

    private function user(string $name): User
    {
        return $this->userNamed($name);
    }

    private function secret(string $name): string
    {
        return (string) $this->people[$name][1];
    }

    private function loginAs(string $name): void
    {
        $this->submitLogin($name);
        $this->submitTotp(self::totp($this->secret($name)));
    }

    private function loginThroughAuthorize(string $name, string $authorizeUrl): void
    {
        $this->browser->request('GET', $authorizeUrl);
        $this->submitLogin($name);
        $this->browser->followRedirect();
        $this->submitTotp(self::totp($this->secret($name)));
        $this->browser->followRedirect();
    }

    private function confirm(string $name): void
    {
        // A fresh code: the one used at login may not be reused for the confirmation.
        $code = self::totp($this->secret($name));
        $this->browser->submit($this->browser->getCrawler()->selectButton('Start')->form(['reason' => 'Ticket #12, dealer reports missing vehicles', 'code' => $code]));
        self::assertResponseRedirects();
        $this->browser->followRedirect();
    }

    private function grant(string $actor, string $target, string $domain, string $rights): void
    {
        // The token comes from a page with a grant form (there is none on a page the actor may not change).
        $crawler = $this->browser->request('GET', '/admin/users/'.$this->user('dave')->getId());
        $token = $crawler->filter('form[action$="/grants"] input[name=_csrf_token]')->attr('value');
        $this->browser->request('POST', '/admin/users/'.$this->user($target)->getId().'/grants', [
            'domain' => $domain, 'rights' => $rights, 'subdomains' => '1', '_csrf_token' => $token,
        ]);
        $this->browser->followRedirect();
    }

    /**
     * @return array{array<mixed>, DpopProofFactory}
     */
    private function tokensFor(string $name): array
    {
        $pkce = self::pkce();
        $key = DpopProofFactory::withNewKey();
        $this->loginThroughAuthorize($name, self::authorizeUrl($pkce['challenge']));
        [$status, $tokens] = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $this->codeFromRedirect(), 'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $pkce['verifier']], $key);
        self::assertSame(200, $status, (string) json_encode($tokens));

        return [$tokens, $key];
    }

    /**
     * @param array<mixed> $tokens
     */
    private function principal(array $tokens, DpopProofFactory $key): Principal
    {
        $this->browser->request('GET', '/.well-known/jwks.json');
        $guard = new ResourceGuard(
            new AccessTokenValidator(StaticJwksProvider::fromJwksJson((string) $this->browser->getResponse()->getContent())),
            new DpopProofValidator(new InMemoryReplayStore()),
        );

        return $guard->authenticate('GET', self::API, 'DPoP '.self::stringAt($tokens, 'access_token'), [$key->create('GET', self::API, self::stringAt($tokens, 'access_token'))]);
    }

    private function assertAccessDenied(): void
    {
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('access_denied', $query['error'] ?? null, $location);
    }

    /**
     * @return array<mixed>
     */
    private function webhookOf(string $type): array
    {
        // Webhooks are flushed at the end of a request: trigger one.
        $this->browser->request('GET', '/health');
        foreach (array_reverse(self::webhooks()) as $hook) {
            if ($type === $hook['type']) {
                return $hook;
            }
        }
        self::fail(\sprintf('No "%s" webhook was sent', $type));
    }
}
