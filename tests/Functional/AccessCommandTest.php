<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\ProfileManager;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * app:access (App\Command\AccessCommand). AdminTestCase: reader is a member of a10 with "vehicle:r".
 */
final class AccessCommandTest extends AdminTestCase
{
    public function testShowsTheRightsOfAUser(): void
    {
        $tester = $this->command('app:access');

        self::assertSame(0, $tester->execute(['username' => 'reader']));
        self::assertStringContainsString('vehicle:r', $tester->getDisplay());
    }

    public function testGivesAnAdjustedProfile(): void
    {
        static::getContainer()->get(ProfileManager::class)->save('fleet', 'Fleet', AccessExpression::parse('vehicle:m'), null, null, true);
        $this->em()->flush();
        $tester = $this->command('app:access');

        self::assertSame(1, $tester->execute(['username' => 'reader', 'domain' => 'a10', 'expression' => 'tire:r', '--access-profile' => 'fleet']));
        self::assertStringContainsString('Unknown resource type(s) or facet(s): tire', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['username' => 'reader', 'domain' => 'a10', 'expression' => 'vehicle:r', '--access-profile' => 'fleet']));
        self::assertStringContainsString('reader: profile fleet, "vehicle:r" on /a1/a10/', $tester->getDisplay());
        self::assertStringContainsString('profile fleet, adjusted: vehicle:r', $tester->getDisplay());
    }

    public function testGivesAProfile(): void
    {
        static::getContainer()->get(ProfileManager::class)->save('fleet', 'Fleet', AccessExpression::parse('vehicle:m'), null, null, true);
        $this->em()->flush();
        $tester = $this->command('app:access');

        self::assertSame(0, $tester->execute(['username' => 'reader', 'domain' => 'a10', '--access-profile' => 'fleet', '--subdomains' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('reader: profile fleet, "vehicle:m" on /a1/a10/ and its subdomains', $display);
        self::assertStringContainsString('profile fleet', $display);

        self::assertSame(1, $tester->execute(['username' => 'reader', 'domain' => 'a10', '--access-profile' => 'ghost']));
        self::assertStringContainsString('Unknown profile "ghost"', $tester->getDisplay());
    }
}
