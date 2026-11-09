<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\ResourceType;
use App\I18n\Locales;
use App\Settings\Duration;
use App\TwoFactor\TwoFactorPolicy;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;

/**
 * The translation catalogues (translations/messages+intl-icu.*.yaml) against the keys the code uses: every key
 * has an English text, no entry is left unused, and every language has the same keys, with valid ICU messages
 * and the same placeholders as in English.
 *
 * Keys are found in the templates ('…'|trans, the literals of a "(a ? '…' : '…')|trans", and any literal shaped
 * like a key of the catalogue) and in the PHP code (new Text('…'), new InvalidInput('…'), new Refusal('…')),
 * plus the keys built at run time from a value (DYNAMIC: "level." ~ level.label).
 */
final class TranslationsTest extends TestCase
{
    private const DOMAIN = 'messages+intl-icu';

    public function testEveryKeyUsedHasAnEnglishText(): void
    {
        $missing = array_diff(array_keys(self::usedKeys(false)), array_keys(self::catalogue('en')));
        sort($missing);

        self::assertSame([], $missing, 'Keys without a text in translations/'.self::DOMAIN.'.en.yaml (make translations).');
    }

    public function testNoEntryIsUnused(): void
    {
        $unused = array_diff(array_keys(self::catalogue('en')), array_keys(self::usedKeys(true)));
        sort($unused);

        self::assertSame([], $unused, 'Entries no code uses any more: remove them from every catalogue.');
    }

    public function testKeysAreLowercaseAndDotted(): void
    {
        foreach (array_keys(self::catalogue('en')) as $key) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key);
        }
    }

    #[DataProvider('locales')]
    public function testEveryLanguageHasTheSameEntries(string $locale): void
    {
        $english = array_keys(self::catalogue('en'));
        $translated = self::catalogue($locale);

        $missing = array_values(array_diff($english, array_keys($translated)));
        $extra = array_values(array_diff(array_keys($translated), $english));
        self::assertSame([], $missing, \sprintf('Missing in %s.', $locale));
        self::assertSame([], $extra, \sprintf('Unknown in English, found in %s.', $locale));
    }

    #[DataProvider('locales')]
    public function testTranslationsAreValidWithTheSamePlaceholders(string $locale): void
    {
        $english = self::catalogue('en');
        foreach (self::catalogue($locale) as $key => $text) {
            self::assertNotSame('', trim($text), \sprintf('Empty translation of "%s" (%s).', $key, $locale));
            self::assertTrue(self::isValidIcu($locale, $text), \sprintf('Invalid ICU message in %s: "%s".', $locale, $text));
            self::assertSame(self::placeholders($english[$key] ?? ''), self::placeholders($text), \sprintf('Placeholders of "%s" differ in %s.', $key, $locale));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (array_keys(Locales::ENABLED) as $locale) {
            if ('en' !== $locale) {
                yield $locale => [$locale];
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function catalogue(string $locale): array
    {
        $path = \dirname(__DIR__).'/translations/'.self::DOMAIN.'.'.$locale.'.yaml';
        self::assertFileExists($path);

        $messages = [];
        foreach ((new YamlFileLoader())->load($path, $locale, self::DOMAIN)->all(self::DOMAIN) as $key => $text) {
            $messages[(string) $key] = \is_string($text) ? $text : '';
        }

        return $messages;
    }

    private static function isValidIcu(string $locale, string $message): bool
    {
        try {
            new \MessageFormatter($locale, $message);

            return true;
        } catch (\IntlException) {
            return false;
        }
    }

    /**
     * @return list<string> names of the arguments of an ICU message ("{count, plural, …}" gives "count")
     */
    private static function placeholders(string $message): array
    {
        preg_match_all('/\{\s*([A-Za-z_]\w*)\s*[,}]/', $message, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * @param bool $anyLiteral also every literal of the templates shaped like a key of the catalogue: may be one
     *                         (macro arguments, texts of the built-in rights table), or not (setting names)
     *
     * @return array<string, true>
     */
    private static function usedKeys(bool $anyLiteral): array
    {
        $root = \dirname(__DIR__);
        $keys = [];
        // Keys built from a value at run time.
        foreach (AccessLevel::cases() as $level) {
            $keys['level.'.$level->label()] = true;
        }
        foreach ([TwoFactorPolicy::MODE_OPTIONAL, TwoFactorPolicy::MODE_GRACE, TwoFactorPolicy::MODE_REQUIRED] as $mode) {
            $keys['two_factor.mode.'.$mode] = true;
        }
        foreach (array_keys(Duration::UNITS) as $unit) {
            $keys['duration.unit.'.$unit] = true;
        }
        foreach (array_keys(ResourceType::RESERVED) as $code) {
            $keys['resource_type.reserved.'.substr($code, 1)] = true;
        }

        $roots = array_fill_keys(array_map(static fn (string $k): string => explode('.', $k)[0], array_keys(self::catalogue('en'))), true);
        $quoted = '\'((?:[^\'\\\\]|\\\\.)*)\'';
        foreach (self::files($root.'/templates', 'twig') as $source) {
            preg_match_all('/'.$quoted.'\s*\|\s*trans\b/', $source, $m);
            self::add($keys, $m[1]);
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"\s*\|\s*trans\b/', $source, $m);
            self::add($keys, $m[1]);
            // "(test ? 'a' : 'b')|trans": the literals of the ternary.
            foreach (self::groupsBeforeTrans($source) as $group) {
                preg_match_all('/[?:]\s*'.$quoted.'/', $group, $m);
                self::add($keys, $m[1]);
            }
            // Texts of the built-in rights table (resource types page).
            preg_match_all('/\b(?:read|write|example):\s*'.$quoted.'/', $source, $m);
            self::add($keys, $m[1]);
            preg_match_all('/'.$quoted.'/', $source, $m);
            foreach ($anyLiteral ? $m[1] : [] as $literal) {
                if (1 === preg_match('/^([a-z][a-z0-9_]*)(\.[a-z0-9_]+)+$/', $literal, $parts) && isset($roots[$parts[1]])) {
                    $keys[$literal] = true;
                }
            }
        }
        foreach (self::files($root.'/src', 'php') as $source) {
            preg_match_all('/new (?:Text|InvalidInput|Refusal)\(\s*'.$quoted.'/', $source, $m);
            self::add($keys, $m[1]);
        }

        // Prefixes of keys built at run time ('resource_type.reserved.' ~ code).
        return array_filter($keys, static fn (string $key): bool => '' !== $key && !str_ends_with($key, '.'), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, true> $keys
     * @param list<string>        $found
     */
    private static function add(array &$keys, array $found): void
    {
        foreach ($found as $key) {
            $keys[$key] = true;
        }
    }

    /**
     * The contents of the parentheses just before each "|trans".
     *
     * @return list<string>
     */
    private static function groupsBeforeTrans(string $source): array
    {
        $groups = [];
        preg_match_all('/\)\s*\|\s*trans\b/', $source, $m, \PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [, $end]) {
            $depth = 0;
            for ($i = $end; $i >= 0; --$i) {
                $depth += match ($source[$i]) {
                    ')' => 1,
                    '(' => -1,
                    default => 0,
                };
                if (0 === $depth) {
                    $groups[] = substr($source, $i, $end - $i + 1);
                    break;
                }
            }
        }

        return $groups;
    }

    /**
     * @return iterable<string>
     */
    private static function files(string $dir, string $extension): iterable
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === $extension) {
                yield (string) file_get_contents($file->getPathname());
            }
        }
    }
}
