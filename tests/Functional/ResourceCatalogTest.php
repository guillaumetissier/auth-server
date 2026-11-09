<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\AccessManager;
use App\Access\ResourceCatalog;
use App\Entity\Domain;
use App\Entity\ResourceFacet;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * Resource types and facets (App\Access\ResourceCatalog). AdminTestCase declares "vehicle", granted to
 * manager ("@access:w @domain:w vehicle:w") and reader ("vehicle:r").
 */
final class ResourceCatalogTest extends AdminTestCase
{
    private ResourceCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = static::getContainer()->get(ResourceCatalog::class);
    }

    public function testFacetsAreDeclaredUnderTheirType(): void
    {
        $facet = $this->catalog->declare('Vehicle.Pricing', 'Prices', ' Sale price, discount ');
        $this->em()->flush();
        $this->em()->clear();

        self::assertInstanceOf(ResourceFacet::class, $facet);
        $type = $this->catalog->types()[0];
        self::assertSame(['vehicle.pricing'], array_map(static fn (ResourceFacet $f): string => $f->getCode(), $type->getFacets()));
        self::assertSame('Sale price, discount', $type->getFacets()[0]->getDescription());
        self::assertSame(['vehicle' => true, 'vehicle.pricing' => true], $this->catalog->knownCodes());
    }

    public function testFacetOfAnUndeclaredTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declare it before its facets');
        $this->catalog->declare('invoice.lines', 'Lines');
    }

    public function testReservedTypesCannotBeDeclaredNorHaveFacets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->declare('@access.users', 'Users');
    }

    public function testInvalidFacetNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->declare('vehicle.9lives', 'Nope');
    }

    public function testUnknownFacetsAreReported(): void
    {
        $this->catalog->declare('vehicle.pricing', 'Prices');
        $this->em()->flush();

        self::assertNull($this->catalog->unknownTypes(AccessExpression::parse('vehicle:r vehicle.pricing:w @access:r')));
        self::assertSame(
            'Unknown resource type(s) or facet(s): vehicle.media, tire. Declare them first.',
            (string) $this->catalog->unknownTypes(AccessExpression::parse('vehicle.media:w tire:r')),
        );
    }

    public function testLabelAndDescriptionChange(): void
    {
        $this->catalog->declare('vehicle.pricing', 'Prices', 'Sale price');
        $this->catalog->declare('vehicle', 'Cars');
        $this->em()->flush();
        $this->catalog->declare('vehicle.pricing', 'Pricing', null);
        $this->em()->flush();
        $this->em()->clear();

        $type = $this->catalog->types()[0];
        self::assertSame('Cars', $type->getLabel());
        self::assertSame('Pricing', $type->getFacets()[0]->getLabel());
        self::assertNull($type->getFacets()[0]->getDescription());
    }

    public function testUsedTypesAndFacetsCannotBeRemoved(): void
    {
        $this->catalog->declare('vehicle.pricing', 'Prices');
        $this->em()->flush();
        $this->grantTo('south', 'a11', 'vehicle.pricing:w');

        self::assertSame(['vehicle' => 3, 'vehicle.pricing' => 1], array_intersect_key($this->catalog->usageCounts(), ['vehicle' => 0, 'vehicle.pricing' => 0]));
        self::assertSame(['rights of south on /a1/a11/'], array_map(strval(...), $this->catalog->usages('vehicle.pricing')));

        try {
            $this->catalog->remove('vehicle.pricing');
            self::fail('A used facet must not be removed');
        } catch (\DomainException $e) {
            self::assertStringContainsString('rights of south on /a1/a11/', $e->getMessage());
        }

        try {
            $this->catalog->remove('vehicle');
            self::fail('A type with facets must not be removed');
        } catch (\DomainException $e) {
            self::assertStringContainsString('still has facets (vehicle.pricing)', $e->getMessage());
        }
    }

    public function testUnusedFacetThenUnusedTypeAreRemoved(): void
    {
        $this->catalog->declare('invoice', 'Invoices');
        $this->catalog->declare('invoice.lines', 'Lines');
        $this->em()->flush();

        $this->catalog->remove('invoice.lines');
        $this->em()->flush();
        $this->catalog->remove('invoice');
        $this->em()->flush();
        $this->em()->clear();

        self::assertSame(['vehicle' => true], $this->catalog->knownCodes());
    }

    public function testRemovingAnUnknownCodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->remove('vehicle.nothing');
    }

    private function grantTo(string $username, string $domain, string $rights): void
    {
        $manager = static::getContainer()->get(AccessManager::class);
        $manager->grant($this->userNamed($username), $this->domain($domain), AccessExpression::parse($rights), false, null);
        $this->em()->flush();
    }

    private function domain(string $code): Domain
    {
        return $this->em()->getRepository(Domain::class)->findOneBy(['code' => $code]) ?? throw new \LogicException($code);
    }
}
