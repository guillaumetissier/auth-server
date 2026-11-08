<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AccessProfile;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessProfileTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function combinations(): iterable
    {
        yield 'no adjustment' => ['vehicle:w invoice:r', '', 'invoice:r vehicle:w'];
        yield 'replaces the rule of a type' => ['vehicle:w invoice:r', 'invoice:-', 'invoice:- vehicle:w'];
        yield 'adds a type' => ['vehicle:r', 'invoice:r', 'invoice:r vehicle:r'];
        yield 'adds a facet' => ['vehicle:w', 'vehicle.pricing:r', 'vehicle:w vehicle.pricing:r'];
        yield 'replaces the wildcard' => ['*:r invoice:-', '*:w', '*:w invoice:-'];
        yield 'own scope apart' => ['user:r user:r:own', 'user:w:own', 'user:r user:w:own'];
        yield 'general rule leaves own rule' => ['user:r:own', 'user:w', 'user:w user:r:own'];
        yield 'reserved types' => ['@access:r vehicle:r', '@access:w', '@access:w vehicle:r'];
    }

    #[DataProvider('combinations')]
    public function testCombine(string $profile, string $adjustments, string $expected): void
    {
        $combined = AccessProfile::combine(AccessExpression::parse($profile), AccessExpression::parse($adjustments));

        self::assertSame(AccessExpression::parse($expected)->toString(), $combined->toString());
    }

    public function testUpdateReportsChanges(): void
    {
        $profile = new AccessProfile('pricer', 'Pricer', AccessExpression::parse('vehicle.pricing:w'), ' Changes prices ');
        self::assertSame('Changes prices', $profile->getDescription());

        self::assertFalse($profile->update('Pricer', AccessExpression::parse('vehicle.pricing:w'), 'Changes prices'));
        self::assertTrue($profile->update('Pricer', AccessExpression::parse('vehicle.pricing:w vehicle.media:w'), 'Changes prices'));
        self::assertSame('vehicle.media:w vehicle.pricing:w', $profile->getExpression()->toString());
        self::assertTrue($profile->update('Pricing', $profile->getExpression(), ''));
        self::assertNull($profile->getDescription());
    }

    public function testInvalidCodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessProfile('Fleet Manager', 'Fleet manager', AccessExpression::parse('vehicle:m'));
    }
}
