<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Settings\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, 'minutes'|'hours'|'days', string}>
     */
    public static function durations(): iterable
    {
        yield '15 minutes' => ['PT15M', 15, 'minutes', '15 minutes'];
        yield '90 minutes' => ['PT90M', 90, 'minutes', '90 minutes'];
        yield '1 hour' => ['PT60M', 1, 'hours', '1 hour'];
        yield '30 days' => ['P30D', 30, 'days', '30 days'];
        yield '48 hours are 2 days' => ['PT48H', 2, 'days', '2 days'];
        yield '1 day' => ['P1D', 1, 'days', '1 day'];
    }

    /**
     * @param 'minutes'|'hours'|'days' $unit
     */
    #[DataProvider('durations')]
    public function testSplitAndHuman(string $iso, int $amount, string $unit, string $human): void
    {
        self::assertSame([$amount, $unit], Duration::split($iso));
        self::assertSame($human, Duration::human($iso));
        self::assertSame(Duration::seconds($iso), Duration::seconds(Duration::toIso($amount, $unit)));
    }

    public function testSeconds(): void
    {
        self::assertSame(900, Duration::seconds('PT15M'));
        self::assertSame(30 * 86400, Duration::seconds('P30D'));
    }
}
