<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * app:profile (App\Command\ProfileCommand). AdminTestCase declares "vehicle".
 */
final class ProfileCommandTest extends AdminTestCase
{
    public function testCreatesChangesShowsAndRemovesAProfile(): void
    {
        $tester = $this->command('app:profile');

        self::assertSame(0, $tester->execute(['code' => 'fleet', 'name' => 'Fleet', 'rights' => 'vehicle:r', '--description' => 'Sees the fleet']));
        self::assertStringContainsString('Profile "fleet" saved', $tester->getDisplay());

        // Only the name: rights and description stay.
        self::assertSame(0, $tester->execute(['code' => 'fleet', 'name' => 'Fleet team']));
        self::assertSame(0, $tester->execute([]));
        $listing = $tester->getDisplay();
        self::assertStringContainsString('Fleet team', $listing);
        self::assertStringContainsString('vehicle:r', $listing);
        self::assertStringContainsString('Sees the fleet', $listing);

        self::assertSame(0, $tester->execute(['code' => 'fleet']));
        self::assertStringContainsString('Fleet team (fleet): vehicle:r', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['code' => 'fleet', '--remove' => true]));
        self::assertStringContainsString('Profile "fleet" removed', $tester->getDisplay());
    }

    public function testErrors(): void
    {
        $tester = $this->command('app:profile');

        self::assertSame(1, $tester->execute(['code' => 'ghost']));
        self::assertStringContainsString('Unknown profile "ghost"', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['code' => 'tires', 'name' => 'Tires', 'rights' => 'tire:r']));
        self::assertStringContainsString('Unknown resource type(s) or facet(s): tire', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['code' => 'bad', 'name' => 'Bad', 'rights' => 'vehicle:x']));
        self::assertStringContainsString('unknown level', $tester->getDisplay());
    }
}
