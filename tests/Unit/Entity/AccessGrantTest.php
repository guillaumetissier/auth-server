<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AccessGrant;
use App\Entity\AccessProfile;
use App\Entity\Domain;
use App\Entity\User;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use PHPUnit\Framework\TestCase;

final class AccessGrantTest extends TestCase
{
    public function testWithoutProfileTheRightsAreItsOwn(): void
    {
        $grant = $this->grant(AccessExpression::parse('vehicle:r'));

        self::assertNull($grant->getProfile());
        self::assertSame('vehicle:r', $grant->getExpression()->toString());
        self::assertSame('vehicle:r', $grant->getAdjustments()->toString());
    }

    public function testProfileRightsAreAdjustedAndFollowTheProfile(): void
    {
        $profile = new AccessProfile('fleet', 'Fleet manager', AccessExpression::parse('vehicle:m invoice:r'));
        $grant = $this->grant(AccessExpression::parse('invoice:-'), $profile);

        self::assertSame('invoice:- vehicle:m', $grant->getExpression()->toString());
        self::assertSame('invoice:-', $grant->getAdjustments()->toString());

        $profile->update('Fleet manager', AccessExpression::parse('vehicle:w invoice:r tire:r'), null);
        self::assertSame('invoice:- tire:r vehicle:w', $grant->getExpression()->toString(), 'linked, not copied');
    }

    public function testUpdateCanDropTheProfile(): void
    {
        $grant = $this->grant(AccessExpression::parse(''), new AccessProfile('fleet', 'Fleet manager', AccessExpression::parse('vehicle:m')));
        $grant->update(AccessExpression::parse('vehicle:r'), true, null);

        self::assertNull($grant->getProfile());
        self::assertSame('vehicle:r', $grant->getExpression()->toString());
    }

    private function grant(AccessExpression $expression, ?AccessProfile $profile = null): AccessGrant
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $domain = (new \ReflectionClass(Domain::class))->newInstanceWithoutConstructor();

        return new AccessGrant($user, $domain, $expression, true, null, $profile);
    }
}
