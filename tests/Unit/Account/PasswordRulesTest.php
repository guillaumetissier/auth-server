<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use App\Account\PasswordRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null, string|null}>
     */
    public static function passwords(): iterable
    {
        yield 'long enough' => ['correct horse battery', null, null];
        yield 'exactly 12' => ['123456789012', null, null];
        yield 'too short' => ['12345678901', null, 'at least 12 characters'];
        yield 'multibyte characters count once' => ['ééééééééééé', null, 'at least 12 characters'];
        yield 'confirmation matches' => ['correct horse battery', 'correct horse battery', null];
        yield 'confirmation differs' => ['correct horse battery', 'correct horse batterY', 'different'];
        yield 'too long' => [str_repeat('a', PasswordRules::MAX_LENGTH + 1), null, 'too long'];
    }

    #[DataProvider('passwords')]
    public function testProblem(string $password, ?string $confirmation, ?string $expected): void
    {
        $problem = PasswordRules::problem($password, $confirmation);

        null === $expected ? self::assertNull($problem) : self::assertStringContainsString($expected, (string) $problem);
    }
}
