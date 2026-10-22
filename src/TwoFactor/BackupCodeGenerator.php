<?php

declare(strict_types=1);

namespace App\TwoFactor;

/**
 * One-time recovery codes, shown once to the user and stored hashed.
 */
final class BackupCodeGenerator
{
    // Crockford-like alphabet without ambiguous characters (0/O, 1/I/L).
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * @return list<string> codes formatted as XXXXX-XXXXX (~49 bits of entropy each)
     */
    public function generate(int $count = 10): array
    {
        $codes = [];
        $max = \strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < $count; ++$i) {
            $code = '';
            for ($j = 0; $j < 10; ++$j) {
                $code .= self::ALPHABET[random_int(0, $max)];
            }
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
        }

        return $codes;
    }
}
