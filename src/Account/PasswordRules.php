<?php

declare(strict_types=1);

namespace App\Account;

use App\I18n\Text;

/**
 * What a new password must look like, wherever it is chosen (profile, password link, command line).
 */
final class PasswordRules
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 4096;

    /**
     * @return Text|null why the password is refused, null when it is acceptable
     */
    public static function problem(string $password, ?string $confirmation = null): ?Text
    {
        if (null !== $confirmation && $password !== $confirmation) {
            return new Text('account.password_rules.two_passwords_are_different');
        }
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            return new Text('account.password_rules.password_must_be_at_least', ['{min}' => self::MIN_LENGTH]);
        }
        if ($length > self::MAX_LENGTH) {
            return new Text('account.password_rules.password_is_too_long');
        }

        return null;
    }
}
