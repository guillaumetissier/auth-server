<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\AccessManager;
use App\Access\ProfileManager;
use App\Entity\AccessProfile;
use App\Entity\Domain;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * Profiles pages (App\Controller\AdminProfileController), and giving a profile from a user page.
 * AdminTestCase: root (super-administrator), support (read-only), manager ("@access:w @domain:w vehicle:w"
 * on a10), reader ("vehicle:r" on a10).
 */
final class AdminProfileControllerTest extends AdminTestCase
{
    public function testSuperAdministratorCreatesAProfile(): void
    {
        $this->signIn('root');
        $this->submitDialog('/admin/profiles', ['name' => 'Vehicle reader', 'code' => 'vehicle-reader', 'rights' => 'vehicle:r', 'description' => 'Sees the vehicles']);
        self::assertResponseRedirects('/admin/profiles');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'Profile "Vehicle reader" created');
        self::assertSelectorTextContains('table', 'Vehicle reader');
        self::assertSelectorTextContains('table', 'unused');

        $this->browser->request('GET', '/admin/profiles/vehicle-reader');
        self::assertSelectorTextContains('dl', 'Sees the vehicles');
        self::assertSelectorTextContains('table', 'Nobody has this profile yet');
    }

    public function testInvalidRightsAreRefusedAndTheFormKept(): void
    {
        $this->signIn('root');
        $this->submitDialog('/admin/profiles', ['name' => 'Tires', 'code' => 'tires', 'rights' => 'tire:r']);
        self::assertResponseIsSuccessful();
        // The dialog comes back open, with the error and what was typed.
        self::assertSelectorExists('#profile-dialog[data-open]');
        self::assertSelectorTextContains('#profile-dialog .alert.error', 'Unknown resource type(s) or facet(s): tire');
        self::assertSame('Tires', $this->browser->getCrawler()->filter('#p-name')->attr('value'));
    }

    public function testManagerGivesAnAdjustedProfileFromTheUserPage(): void
    {
        $this->createProfile('fleet', 'Fleet', 'vehicle:w');
        $this->signIn('manager');
        $reader = $this->userNamed('reader');
        $crawler = $this->browser->request('GET', '/admin/users/'.$reader->getId());
        $form = $crawler->filter('#g-profile')->closest('form')?->form();
        self::assertNotNull($form);
        $this->browser->submit($form, ['domain' => 'a10', 'profile' => 'fleet', 'rights' => '']);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'profile Fleet: vehicle:w');
        self::assertSelectorTextContains('table', 'Fleet');
        self::assertSelectorTextContains('table', '= vehicle:w');

        // Beyond his own rights: "vehicle:m" through an adjustment.
        $form = $this->browser->getCrawler()->filter('#g-profile')->closest('form')?->form();
        self::assertNotNull($form);
        $this->browser->submit($form, ['domain' => 'a10', 'profile' => 'fleet', 'rights' => 'vehicle:m']);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'exceed your own rights');
    }

    public function testChangingAGivenProfileAsksForConfirmation(): void
    {
        $fleet = $this->createProfile('fleet', 'Fleet', 'vehicle:r');
        static::getContainer()->get(AccessManager::class)->grant($this->userNamed('reader'), $this->em()->getRepository(Domain::class)->findOneBy(['code' => 'a10']) ?? throw new \LogicException(), AccessExpression::parse(''), true, null, $fleet);

        $this->signIn('root');
        $this->browser->request('GET', '/admin/profiles/fleet');
        self::assertSelectorTextContains('table', 'reader');
        $edit = $this->browser->getCrawler()->filter('button[data-open-profile="edit"]');
        self::assertSame('vehicle:r', $edit->attr('data-rights'));

        // The dialog posts to the profile, as the script sets it.
        $this->submitDialog('/admin/profiles/fleet', ['code' => 'fleet', 'name' => 'Fleet', 'rights' => 'vehicle:w', 'from' => 'detail'], '/admin/profiles/fleet');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#profile-dialog[data-open]');
        self::assertSelectorTextContains('#profile-dialog section.impact', 'These rights change 1 grant');
        self::assertSelectorTextContains('#profile-dialog section.impact', 'vehicle:w');
        self::assertSelectorTextContains('#profile-dialog button[type=submit]', 'Confirm and change 1 grant');
        self::assertSame('vehicle:r', $this->profiles()->find('fleet')?->getExpression()->toString(), 'not saved yet');

        $this->browser->submit($this->browser->getCrawler()->filter('#profile-dialog form')->form());
        self::assertResponseRedirects('/admin/profiles/fleet');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'the rights of 1 grant changed');
        $this->em()->clear();
        self::assertSame('vehicle:w', $this->profiles()->find('fleet')?->getExpression()->toString());
    }

    public function testRenamingWithoutChangingRightsNeedsNoConfirmation(): void
    {
        $this->createProfile('fleet', 'Fleet', 'vehicle:r');
        $this->signIn('root');
        $this->submitDialog('/admin/profiles/fleet', ['code' => 'fleet', 'name' => 'Fleet team', 'rights' => 'vehicle:r']);
        self::assertResponseRedirects('/admin/profiles');
    }

    public function testRemoval(): void
    {
        $this->createProfile('unused', 'Unused', 'vehicle:r');
        $this->signIn('root');
        $crawler = $this->browser->request('GET', '/admin/profiles');
        $form = $crawler->filter('form[data-confirm-title]')->form();
        $this->browser->submit($form);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'Profile "Unused" removed');
    }

    public function testPlatformAdministratorOnlyLooks(): void
    {
        $this->createProfile('fleet', 'Fleet', 'vehicle:r');
        $this->signIn('support');
        $this->browser->request('GET', '/admin/profiles');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-confirm-title]');
        self::assertSelectorNotExists('#profile-dialog');
        self::assertSelectorNotExists('[data-open-profile]');
        $this->browser->request('GET', '/admin/profiles/fleet');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#profile-dialog');

        $this->browser->request('POST', '/admin/profiles', ['code' => 'x', 'name' => 'X', 'rights' => '*:m']);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/admin/profiles/fleet', ['name' => 'X', 'rights' => '*:m']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Opens $page and submits its profile dialog to $action, as the script leaves it.
     *
     * @param array<string, string> $values
     */
    private function submitDialog(string $action, array $values, string $page = '/admin/profiles'): void
    {
        $crawler = $this->browser->request('GET', $page);
        $form = $crawler->filter('#profile-dialog form')->form(null, 'POST');
        $this->browser->request('POST', $action, $values + $form->getValues());
    }

    private function createProfile(string $code, string $name, string $rights): AccessProfile
    {
        $profile = $this->profiles()->save($code, $name, AccessExpression::parse($rights), null, null, true);
        $this->em()->flush();

        return $profile;
    }

    private function profiles(): ProfileManager
    {
        return static::getContainer()->get(ProfileManager::class);
    }
}
