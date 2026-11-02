<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccessGrant;
use App\Entity\Domain;

/**
 * Administration of domains (App\Controller\AdminDomainController).
 */
final class AdminDomainControllerTest extends AdminTestCase
{
    public function testListShowsMembersPerDomain(): void
    {
        $this->signIn('root');
        $crawler = $this->browser->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $rows = [];
        foreach ($crawler->filter('tbody tr') as $tr) {
            $cells = array_map('trim', explode("\n", trim($tr->textContent)));
            $rows[$cells[0]] = $tr->textContent;
        }
        self::assertStringContainsString('3', $rows['a10'], 'owner, manager, reader');
        self::assertCount(4, $rows);
    }

    public function testManagerOfTheParentRenamesASubdomain(): void
    {
        $this->signIn('manager');
        $this->browser->request('GET', '/admin/domains/a100');
        $this->submitForm('/admin/domains/a100/rename', ['name' => 'Northern depot']);
        self::assertResponseRedirects('/admin/domains/a100');
        self::assertSame('Northern depot', $this->domain('a100')?->getName());

        // a10 itself is renamed from above only.
        $this->browser->request('GET', '/admin/domains/a10');
        self::assertSelectorNotExists('form[action="/admin/domains/a10/rename"]');
    }

    public function testOnlyEmptyDomainsAreDeleted(): void
    {
        $this->signIn('manager');
        $this->browser->request('GET', '/admin/domains/a100');
        self::assertSelectorTextContains('section.danger', 'The domain has 1 user(s)');
        self::assertSelectorNotExists('form[action="/admin/domains/a100/delete"]');

        // Creates an empty subdomain, with a grant on it, then deletes it.
        $this->browser->request('GET', '/admin/domains/a10');
        $this->submitForm('/admin/domains/new', ['code' => 'a102', 'name' => 'Temporary']);
        self::assertResponseRedirects('/admin/domains/a102');
        $a102 = $this->domainOrFail('a102');
        $this->em()->persist(new AccessGrant($this->userNamed('dealer'), $a102, \GuillaumeTissier\ResourceGuard\Access\AccessExpression::parse('vehicle:r'), false, null));
        $this->em()->flush();

        $this->browser->request('GET', '/admin/domains/a102');
        $this->submitForm('/admin/domains/a102/delete', ['confirm' => 'nope']);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'Type the code of the domain (a102)');
        self::assertNotNull($this->domain('a102'));

        $this->submitForm('/admin/domains/a102/delete', ['confirm' => 'a102']);
        self::assertResponseRedirects('/admin/domains/a10');
        self::assertNull($this->domain('a102'));
        self::assertSame(2, $this->em()->getRepository(AccessGrant::class)->count([]), 'only the grants of manager and reader remain');
    }

    public function testDomainWithSubdomainsCannotBeDeleted(): void
    {
        $this->signIn('root');
        $this->browser->request('GET', '/admin/domains/a10');
        self::assertSelectorTextContains('section.danger', 'The domain has 1 subdomain(s)');

        $this->browser->request('POST', '/admin/domains/a10/delete', [
            'confirm' => 'a10',
            '_csrf_token' => $this->browser->getCrawler()->filter('form[action="/admin/domains/a10/rename"] input[name=_csrf_token]')->attr('value'),
        ]);
        self::assertResponseRedirects('/admin/domains/a10');
        self::assertNotNull($this->domain('a10'));
    }

    public function testReadOnlyAdministratorChangesNothing(): void
    {
        $this->signIn('support');
        $this->browser->request('GET', '/admin/domains/a100');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/admin/domains/a100/rename"]');
        self::assertSelectorNotExists('section.danger');
    }

    private function domain(string $code): ?Domain
    {
        $this->em()->clear();

        return $this->em()->getRepository(Domain::class)->findOneBy(['code' => $code]);
    }

    private function domainOrFail(string $code): Domain
    {
        return $this->domain($code) ?? throw new \LogicException('no domain '.$code);
    }
}
