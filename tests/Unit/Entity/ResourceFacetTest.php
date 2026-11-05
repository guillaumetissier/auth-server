<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ResourceFacet;
use App\Entity\ResourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResourceFacetTest extends TestCase
{
    public function testCodeIsTheTypeAndTheName(): void
    {
        $type = new ResourceType('vehicle', 'Vehicles');
        $facet = new ResourceFacet($type, 'pricing', 'Prices', '  Sale price  ');

        self::assertSame('vehicle.pricing', $facet->getCode());
        self::assertSame('pricing', $facet->getName());
        self::assertSame('Sale price', $facet->getDescription());
        self::assertSame([$facet], $type->getFacets(), 'added to its type at once');
    }

    public function testDescribeReportsChanges(): void
    {
        $facet = new ResourceFacet(new ResourceType('vehicle', 'Vehicles'), 'pricing', 'Prices', 'Sale price');

        self::assertFalse($facet->describe('Prices', 'Sale price'));
        self::assertTrue($facet->describe('Prices', ''));
        self::assertNull($facet->getDescription());
        self::assertTrue($facet->describe('Pricing', null));
        self::assertSame('Pricing', $facet->getLabel());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'uppercase' => ['Pricing'];
        yield 'dot' => ['pricing.vat'];
        yield 'starts with a digit' => ['9lives'];
        yield 'empty' => [''];
        yield 'space' => ['sale price'];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNamesAreRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResourceFacet(new ResourceType('vehicle', 'Vehicles'), $name, 'x');
    }
}
