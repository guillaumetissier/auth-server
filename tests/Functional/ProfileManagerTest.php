<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\AccessManager;
use App\Access\ProfileManager;
use App\Access\ResourceCatalog;
use App\Entity\AccessProfile;
use App\Entity\Domain;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * Access profiles (App\Access\ProfileManager). AdminTestCase declares "vehicle"; reader holds "vehicle:r" on a10.
 */
final class ProfileManagerTest extends AdminTestCase
{
    private ProfileManager $profiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profiles = static::getContainer()->get(ProfileManager::class);
        static::getContainer()->get(ResourceCatalog::class)->declare('invoice', 'Invoices');
        $this->em()->flush();
    }

    public function testCreatesAndListsProfiles(): void
    {
        $this->profiles->save('fleet', 'Fleet manager', AccessExpression::parse('vehicle:m invoice:r'), 'Runs the fleet', null, true);
        $this->profiles->save('auditor', 'Auditor', AccessExpression::parse('*:r'), null, null, true);
        $this->em()->flush();

        self::assertSame(['Auditor', 'Fleet manager'], array_map(static fn (AccessProfile $p): string => $p->getName(), $this->profiles->profiles()));
        self::assertSame('invoice:r vehicle:m', $this->profiles->find('FLEET')?->getExpression()->toString());
    }

    public function testInvalidProfilesAreRefused(): void
    {
        $this->profiles->save('fleet', 'Fleet manager', AccessExpression::parse('vehicle:m'), null, null, true);
        $this->em()->flush();

        foreach ([
            ['fleet', 'Again', 'vehicle:r', true, 'already exists'],
            ['ghost', 'Ghost', 'vehicle:r', false, 'Unknown profile "ghost"'],
            ['tires', 'Tires', 'tire:r', true, 'Unknown resource type(s) or facet(s): tire'],
            ['empty', 'Empty', '', true, 'at least one rule'],
            ['noname', ' ', 'vehicle:r', true, 'Give the profile a name'],
        ] as [$code, $name, $rights, $create, $message]) {
            try {
                $this->profiles->save($code, $name, AccessExpression::parse($rights), null, null, $create);
                self::fail('Refused: '.$message);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testChangingAProfileChangesTheRightsOfItsUsers(): void
    {
        $fleet = $this->profiles->save('fleet', 'Fleet manager', AccessExpression::parse('vehicle:m invoice:r'), null, null, true);
        $this->em()->flush();
        $this->give('reader', 'a10', $fleet, 'invoice:-');
        $this->give('south', 'a11', $fleet, '');

        self::assertSame(['fleet' => 2], $this->profiles->usageCounts());

        $impact = $this->profiles->impact($fleet, AccessExpression::parse('vehicle:w invoice:r'));
        self::assertCount(2, $impact);
        self::assertSame('invoice:- vehicle:m', $impact[0]['before']->toString());
        self::assertSame('invoice:- vehicle:w', $impact[0]['after']->toString(), 'adjustments kept');

        self::assertCount(1, $this->profiles->impact($fleet, AccessExpression::parse('vehicle:m invoice:w')), 'reader adjusts invoices: unchanged for him');

        $this->profiles->save('fleet', 'Fleet manager', AccessExpression::parse('vehicle:w invoice:r'), null, null, false);
        $this->em()->flush();
        $this->em()->clear();
        $grant = static::getContainer()->get(AccessManager::class)->findGrant($this->userNamed('reader'), $this->domain('a10'));
        self::assertSame('invoice:- vehicle:w', $grant?->getExpression()->toString());
    }

    public function testAProfileGivenToUsersIsNotRemoved(): void
    {
        $fleet = $this->profiles->save('fleet', 'Fleet manager', AccessExpression::parse('vehicle:m'), null, null, true);
        $unused = $this->profiles->save('unused', 'Unused', AccessExpression::parse('vehicle:r'), null, null, true);
        $this->em()->flush();
        $this->give('reader', 'a10', $fleet, '');

        try {
            $this->profiles->remove($fleet, null);
            self::fail('Given profile removed');
        } catch (\DomainException $e) {
            self::assertStringContainsString('still given to 1 user: reader on /a1/a10/', $e->getMessage());
        }

        $this->profiles->remove($unused, null);
        $this->em()->flush();
        self::assertNull($this->profiles->find('unused'));
    }

    public function testTypesNamedByAProfileStayInUse(): void
    {
        $this->profiles->save('billing', 'Billing', AccessExpression::parse('invoice:w'), null, null, true);
        $this->em()->flush();

        self::assertSame(['profile billing'], array_map(strval(...), static::getContainer()->get(ResourceCatalog::class)->usages('invoice')));
    }

    private function give(string $username, string $domain, AccessProfile $profile, string $adjustments): void
    {
        static::getContainer()->get(AccessManager::class)->grant($this->userNamed($username), $this->domain($domain), AccessExpression::parse($adjustments), true, null, $profile);
    }

    private function domain(string $code): Domain
    {
        return $this->em()->getRepository(Domain::class)->findOneBy(['code' => $code]) ?? throw new \LogicException($code);
    }
}
