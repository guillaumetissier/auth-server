<?php

declare(strict_types=1);

namespace App\I18n;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Invalid input, with a message shown to people in their language (Text::of()). getMessage() is English.
 */
final class InvalidInput extends \InvalidArgumentException implements TranslatableInterface
{
    private readonly Text $text;

    /**
     * @param array<string, string|int|float|\DateTimeInterface|Text|list<string|Text>> $parameters
     */
    public function __construct(string $key, array $parameters = [])
    {
        $this->text = new Text($key, $parameters);
        parent::__construct((string) $this->text);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->text->trans($translator, $locale);
    }
}
