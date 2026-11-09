<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use App\I18n\InvalidInput;
use App\I18n\Refusal;
use App\I18n\Text;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class TextTest extends TestCase
{
    public function testCastToStringGivesTheEnglishTextOfTheKey(): void
    {
        self::assertSame('Profile "Pricer" saved.', (string) new Text('admin.profile.profile_name_saved', ['{name}' => 'Pricer']));
        self::assertSame('Only 1 backup code left: generate new ones from your profile.', (string) new Text('dashboard.only_count_left_generate_new', ['{count}' => 1]));
        self::assertSame('Only 3 backup codes left: generate new ones from your profile.', (string) new Text('dashboard.only_count_left_generate_new', ['{count}' => 3]));
        self::assertSame('no.such.key', (string) new Text('no.such.key'), 'an unknown key stays as it is');
    }

    public function testParametersMayBeTextsOrLists(): void
    {
        $where = [new Text('access.catalog.profile_code', ['{code}' => 'fleet']), new Text('access.catalog.access_default_expression')];
        $text = new Text('access.catalog.code_is_still_used_in', ['{code}' => 'tire', '{count}' => 2, '{places}' => $where, '{more}' => '']);

        self::assertSame('"tire" is still used in 2 places: profile fleet; ACCESS_DEFAULT_EXPRESSION. Change these rights first.', (string) $text);
    }

    public function testTranslatedWithItsParametersTranslatedToo(): void
    {
        $translator = new Translator('fr', new MessageFormatter());
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'used.in' => 'Utilisé dans : {places}.',
            'profile' => 'profil {code}',
            'grants' => '{count, plural, one {# attribution} other {# attributions}}',
        ], 'fr', 'messages+intl-icu');

        $text = new Text('used.in', ['{places}' => [new Text('profile', ['{code}' => 'fleet']), 'x']]);
        self::assertSame('Utilisé dans : profil fleet; x.', $text->trans($translator));
        self::assertSame('2 attributions', (new Text('grants', ['{count}' => 2]))->trans($translator));
    }

    public function testErrorsCarryTheirText(): void
    {
        $input = new InvalidInput('access.profiles.unknown_profile_code', ['{code}' => 'x']);
        $refusal = new Refusal('access.profiles.profile_code_already_exists_edit', ['{code}' => 'y']);

        self::assertSame('Unknown profile "x".', $input->getMessage(), 'the message of the exception is English');
        self::assertSame('The profile "y" already exists: edit it instead.', $refusal->getMessage());
        self::assertSame($input, Text::of($input));
        self::assertSame('plain', Text::of(new \RuntimeException('plain')));
    }
}
