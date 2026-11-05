<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ResourceFacet;
use App\Entity\ResourceType;
use PHPUnit\Framework\TestCase;

final class ResourceTypeTest extends TestCase
{
    public function testCodeCannotContainADot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid resource type');
        new ResourceType('vehicle.pricing', 'Prices');
    }

    public function testFacetsAreSortedAndRemovable(): void
    {
        $type = new ResourceType('vehicle', 'Vehicles');
        $pricing = new ResourceFacet($type, 'pricing', 'Prices');
        $media = new ResourceFacet($type, 'media', 'Photos');

        self::assertSame([$media, $pricing], $type->getFacets());

        $type->removeFacet($media);
        self::assertSame([$pricing], $type->getFacets());
    }
}
