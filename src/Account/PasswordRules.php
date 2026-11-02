<?php

declare(strict_types=1);

namespace App\Account;

/**
 * What a new password must look like, wherever it is chosen (profile, password link, command line).
 */
final class PasswordRules
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 4096;

    /**
     * @return string|null why the password is refused, null when it is acceptable
     */
    public static function problem(string $password, ?string $confirmation = null): ?string
    {
        if (null !== $confirmation && $password !== $confirmation) {
            return 'The two passwords are different.';
        }
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            return \sprintf('The password must be at least %d characters long.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            return 'The password is too long.';
        }

        return null;
    }
}
