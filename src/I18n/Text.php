<?php

declare(strict_types=1);

namespace App\I18n;

use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A text shown to people, translated when displayed: in templates ("|trans"), in flash messages (stored in the
 * session as is), in errors of services. It is a translation key ("admin.profile.profile_name_saved") of
 * translations/messages+intl-icu.*.yaml, whose texts are in ICU format ("Profile "{name}" saved.",
 * "{count, plural, one {# grant} other {# grants}}").
 *
 * Parameters are written with braces ('{name}' => 'Pricer') and may be Texts themselves, lists of texts (joined
 * with "; "), or dates (formatted for the language with "{date, date, medium}"). Cast to string, a Text gives the
 * English text: for logs, the console and exception messages.
 */
final class Text implements TranslatableInterface, \Stringable
{
    /** @var array<string, string>|null English texts by key, read once */
    private static ?array $english = null;

    /**
     * @param array<string, string|int|float|\DateTimeInterface|self|list<string|self>> $parameters
     */
    public function __construct(
        private readonly string $key,
        private readonly array $parameters = [],
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, string|int|float|\DateTimeInterface|self|list<string|self>>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $translate = static fn (string|int|float|\DateTimeInterface|self $value): string|int|float|\DateTimeInterface => $value instanceof self ? $value->trans($translator, $locale) : $value;
        $parameters = array_map(
            static fn (string|int|float|\DateTimeInterface|self|array $value): string|int|float|\DateTimeInterface => \is_array($value) ? implode('; ', array_map(static fn (string|self $item): string => $item instanceof self ? $item->trans($translator, $locale) : $item, $value)) : $translate($value),
            $this->parameters,
        );

        return $translator->trans($this->key, $parameters, 'messages', $locale);
    }

    public function __toString(): string
    {
        $parameters = [];
        foreach ($this->parameters as $name => $value) {
            $parameters[trim($name, '{}')] = \is_array($value) ? implode('; ', array_map(strval(...), $value)) : ($value instanceof self ? (string) $value : $value);
        }
        $message = self::english($this->key);
        $formatted = \MessageFormatter::formatMessage('en', $message, $parameters);

        return false === $formatted ? $message : $formatted;
    }

    /**
     * The English text of a key (the key itself when unknown), without the translator: for __toString().
     */
    public static function english(string $key): string
    {
        if (null === self::$english) {
            self::$english = [];
            $path = \dirname(__DIR__, 2).'/translations/messages+intl-icu.en.yaml';
            $tree = is_file($path) ? Yaml::parseFile($path) : [];
            self::flatten(\is_array($tree) ? $tree : [], '');
        }

        return self::$english[$key] ?? $key;
    }

    /**
     * @param array<mixed> $tree
     */
    private static function flatten(array $tree, string $prefix): void
    {
        foreach ($tree as $name => $value) {
            if (\is_array($value)) {
                self::flatten($value, $prefix.$name.'.');
            } elseif (\is_string($value)) {
                self::$english[$prefix.$name] = $value;
            }
        }
    }

    /**
     * What to show for an error: its Text when it carries one, otherwise its message (English).
     */
    public static function of(\Throwable $error): TranslatableInterface|string
    {
        return $error instanceof TranslatableInterface ? $error : $error->getMessage();
    }
}
