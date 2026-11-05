<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * app:resource-type (App\Command\ResourceTypeCommand). AdminTestCase declares "vehicle", used by two grants.
 */
final class ResourceTypeCommandTest extends AdminTestCase
{
    public function testDeclaresListsAndRemovesAFacet(): void
    {
        $tester = $this->command('app:resource-type');

        self::assertSame(0, $tester->execute(['code' => 'vehicle.pricing', 'label' => 'Prices', '--description' => 'Sale price, discount']));
        self::assertStringContainsString('"vehicle.pricing" saved', $tester->getDisplay());

        // Only the label changes: the description stays.
        self::assertSame(0, $tester->execute(['code' => 'vehicle.pricing', 'label' => 'Pricing']));
        self::assertSame(0, $tester->execute([]));
        $listing = $tester->getDisplay();
        self::assertStringContainsString('vehicle.pricing', $listing);
        self::assertStringContainsString('Pricing', $listing);
        self::assertStringContainsString('Sale price, discount', $listing);
        self::assertStringContainsString('@access', $listing);

        self::assertSame(0, $tester->execute(['code' => 'vehicle.pricing', '--remove' => true]));
        self::assertStringContainsString('"vehicle.pricing" removed', $tester->getDisplay());
    }

    public function testUsedTypeIsNotRemoved(): void
    {
        $tester = $this->command('app:resource-type');

        self::assertSame(1, $tester->execute(['code' => 'vehicle', '--remove' => true]));
        self::assertStringContainsString('still used in 2 place(s)', $tester->getDisplay());
    }

    public function testFacetOfAnUnknownTypeIsRefused(): void
    {
        $tester = $this->command('app:resource-type');

        self::assertSame(1, $tester->execute(['code' => 'boat.hull', 'label' => 'Hull']));
        self::assertStringContainsString('declare it before its facets', $tester->getDisplay());
    }
}
