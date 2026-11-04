<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Lifetimes as stored (ISO 8601 durations: PT15M, P30D…) and as shown in the settings page
 * (an amount and a unit).
 */
final class Duration
{
    public const UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];

    public static function seconds(string $iso): int
    {
        $start = new \DateTimeImmutable('@0');

        return $start->add(new \DateInterval($iso))->getTimestamp() - $start->getTimestamp();
    }

    /**
     * @param key-of<self::UNITS> $unit
     */
    public static function toIso(int $amount, string $unit): string
    {
        return match ($unit) {
            'minutes' => \sprintf('PT%dM', $amount),
            'hours' => \sprintf('PT%dH', $amount),
            'days' => \sprintf('P%dD', $amount),
        };
    }

    /**
     * The largest unit that divides the duration exactly: P30D → [30, 'days'], PT90M → [90, 'minutes'].
     *
     * @return array{int, key-of<self::UNITS>}
     */
    public static function split(string $iso): array
    {
        $seconds = self::seconds($iso);
        foreach (['days', 'hours', 'minutes'] as $unit) {
            if (0 === $seconds % self::UNITS[$unit]) {
                return [intdiv($seconds, self::UNITS[$unit]), $unit];
            }
        }

        return [max(1, intdiv($seconds, 60)), 'minutes'];
    }

    public static function human(string $iso): string
    {
        [$amount, $unit] = self::split($iso);

        return $amount.' '.(1 === $amount ? rtrim($unit, 's') : $unit);
    }
}
