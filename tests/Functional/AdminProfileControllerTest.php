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
        $crawler = $this->browser->request('GET', '/admin/profiles/new');
        $this->browser->submit($crawler->filter('form')->last()->form(), [
            'name' => 'Vehicle reader', 'code' => 'vehicle-reader', 'rights' => 'vehicle:r', 'description' => 'Sees the vehicles',
        ]);
        self::assertResponseRedirects('/admin/profiles/vehicle-reader');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'Profile "Vehicle reader" created');
        self::assertSelectorTextContains('table', 'Nobody has this profile yet');

        $this->browser->request('GET', '/admin/profiles');
        self::assertSelectorTextContains('table', 'Vehicle reader');
        self::assertSelectorTextContains('table', 'unused');
    }

    public function testInvalidRightsAreRefusedAndTheFormKept(): void
    {
        $this->signIn('root');
        $crawler = $this->browser->request('GET', '/admin/profiles/new');
        $this->browser->submit($crawler->filter('form')->last()->form(), ['name' => 'Tires', 'code' => 'tires', 'rights' => 'tire:r']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert.error', 'Unknown resource type(s) or facet(s): tire');
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
        $crawler = $this->browser->request('GET', '/admin/profiles/fleet');
        self::assertSelectorTextContains('table', 'reader');
        $this->browser->submit($crawler->filter('form')->last()->form(), ['rights' => 'vehicle:w']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section.impact', 'These changes affect 1 grant(s)');
        self::assertSelectorTextContains('section.impact', 'vehicle:w');
        self::assertSame('vehicle:r', $this->profiles()->find('fleet')?->getExpression()->toString(), 'not saved yet');

        $this->browser->submit($this->browser->getCrawler()->filter('form')->last()->form());
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.ok', 'the rights of 1 grant(s) changed');
        $this->em()->clear();
        self::assertSame('vehicle:w', $this->profiles()->find('fleet')?->getExpression()->toString());
    }

    public function testRenamingWithoutChangingRightsNeedsNoConfirmation(): void
    {
        $this->createProfile('fleet', 'Fleet', 'vehicle:r');
        $this->signIn('root');
        $crawler = $this->browser->request('GET', '/admin/profiles/fleet');
        $this->browser->submit($crawler->filter('form')->last()->form(), ['name' => 'Fleet team']);
        self::assertResponseRedirects('/admin/profiles/fleet');
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
        $this->browser->request('GET', '/admin/profiles/fleet');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button[type="submit"]');

        $this->browser->request('GET', '/admin/profiles/new');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/admin/profiles/fleet', ['name' => 'X', 'rights' => '*:m']);
        self::assertResponseStatusCodeSame(403);
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
