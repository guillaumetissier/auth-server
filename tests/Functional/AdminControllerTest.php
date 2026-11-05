<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\AccessManager;
use App\Entity\Domain;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * Resource types and facets page (App\Controller\AdminController). AdminTestCase declares "vehicle",
 * granted to manager and reader.
 */
final class AdminControllerTest extends AdminTestCase
{
    public function testSuperAdministratorDeclaresAndDescribesAFacet(): void
    {
        $this->signIn('root');
        $this->submitDialog(['type' => 'vehicle', 'facet' => 'pricing', 'label' => 'Prices', 'description' => 'Sale price, discount']);
        self::assertSelectorTextContains('.alert.ok', '"vehicle.pricing" created.');
        self::assertSelectorTextContains('table', 'vehicle.pricing');
        self::assertSelectorTextContains('table', 'Sale price, discount');

        $edit = $this->browser->getCrawler()->filter('button[data-open-dialog="edit"][data-code="vehicle.pricing"]');
        self::assertSame('Prices', $edit->attr('data-label'));
        self::assertSame('1', $edit->attr('data-facet'));

        $this->submitDialog(['mode' => 'edit', 'code' => 'vehicle.pricing', 'label' => 'Pricing', 'description' => '']);
        self::assertSelectorTextContains('.alert.ok', '"vehicle.pricing" saved.');
        self::assertSelectorTextNotContains('table', 'Sale price, discount');
    }

    public function testCreatingNeverOverwritesAndEditingNeverCreates(): void
    {
        $this->signIn('root');
        $this->submitDialog(['code' => 'vehicle', 'label' => 'Cars']);
        self::assertSelectorTextContains('.alert.error', '"vehicle" already exists: use Edit');

        $this->submitDialog(['mode' => 'edit', 'code' => 'tire', 'label' => 'Tires']);
        self::assertSelectorTextContains('.alert.error', 'Unknown resource type or facet "tire"');

        $this->submitDialog(['code' => 'tire', 'label' => 'Tires']);
        self::assertSelectorTextContains('.alert.ok', '"tire" created.');
    }

    public function testInvalidFacetIsRefusedWithAMessage(): void
    {
        $this->signIn('root');
        $this->submitDialog(['type' => 'vehicle', 'facet' => 'Bad Name', 'label' => 'x']);
        self::assertSelectorTextContains('.alert.error', 'Invalid facet');
    }

    public function testUsedTypeShowsItsUsageAndCannotBeRemoved(): void
    {
        $this->signIn('root');
        $this->browser->request('GET', '/admin/resource-types');
        self::assertSelectorTextContains('tbody tr:first-child', 'in use (2)', 'manager and reader');
        self::assertSame('Change the rights naming it first', $this->browser->getCrawler()->filter('tbody tr:first-child button.danger')->attr('title'));
        self::assertNotNull($this->browser->getCrawler()->filter('tbody tr:first-child button.danger')->attr('disabled'));

        // Posted anyway (stale page): refused with the places where it is used.
        $this->browser->request('POST', '/admin/resource-types', ['code' => 'vehicle', 'mode' => 'remove', '_csrf_token' => $this->csrf()]);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'rights of manager on /a1/a10/');
    }

    public function testUnusedFacetIsRemoved(): void
    {
        $this->signIn('root');
        $this->submitDialog(['type' => 'vehicle', 'facet' => 'media', 'label' => 'Photos']);
        self::assertSelectorTextContains('tr.facet', 'unused');
        $form = $this->browser->getCrawler()->filter('tr.facet button.danger')->form();
        $this->browser->submit($form);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', '"vehicle.media" removed.');
        self::assertSelectorTextNotContains('table', 'vehicle.media');
    }

    public function testPlatformAdministratorOnlyReads(): void
    {
        $this->signIn('support');
        $this->browser->request('GET', '/admin/resource-types');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#rt-dialog');
        self::assertSelectorNotExists('[data-open-dialog]');
        self::assertSelectorTextContains('table', 'in use (2)');

        $this->browser->request('POST', '/admin/resource-types', ['code' => 'tire', 'label' => 'Tires']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testFacetsAppearInTheRightsPreview(): void
    {
        $this->signIn('root');
        $this->submitDialog(['type' => 'vehicle', 'facet' => 'pricing', 'label' => 'Prices']);
        $manager = static::getContainer()->get(AccessManager::class);
        $domain = $this->em()->getRepository(Domain::class)->findOneBy(['code' => 'a10']);
        self::assertNotNull($domain);
        $manager->grant($this->userNamed('reader'), $domain, AccessExpression::parse('vehicle:r vehicle.pricing:w'), false, null);
        $this->em()->flush();

        $this->browser->request('GET', '/admin/users/'.$this->userNamed('reader')->getId());
        self::assertResponseIsSuccessful();
        $row = $this->browser->getCrawler()->filter('tr.facet');
        self::assertCount(1, $row);
        self::assertStringContainsString('vehicle.pricing', $row->text());
        self::assertStringContainsString('write', strtolower($row->text()));
    }

    /**
     * Opens the page and submits the dialog form, as the script leaves it for the chosen mode: the fields
     * of the other modes are disabled there, emptied here.
     *
     * @param array<string, string> $values
     */
    private function submitDialog(array $values): void
    {
        $crawler = $this->browser->request('GET', '/admin/resource-types');
        $form = $crawler->filter('#rt-form')->form();
        $this->browser->submit($form, $values + ['mode' => 'create', 'code' => '', 'facet' => '', 'description' => '']);
        self::assertResponseRedirects('/admin/resource-types');
        $this->browser->followRedirect();
    }

    private function csrf(): string
    {
        $crawler = $this->browser->request('GET', '/admin/resource-types');

        return (string) $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');
    }
}
