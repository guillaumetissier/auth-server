<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Settings\SystemSettings;

/**
 * Saved values take precedence over the environment, which stays the default (App\Settings\SystemSettings).
 */
final class SystemSettingsTest extends OAuthTestCase
{
    public function testEnvironmentDefaultsThenSavedValuesThenDefaultsAgain(): void
    {
        [$root] = $this->createUser('root', 'app-root', withTotp: false);
        $settings = static::getContainer()->get(SystemSettings::class);

        self::assertTrue($settings->dpopRequiredByDefault());
        self::assertTrue($settings->pkceForConfidentialClients());
        self::assertSame('required', $settings->twoFactorMode(), '.env.test');
        self::assertNull($settings->twoFactorDeadline());

        $settings->save([
            SystemSettings::DPOP_REQUIRED => '0',
            SystemSettings::PKCE_CONFIDENTIAL => '0',
            SystemSettings::TWO_FACTOR_MODE => 'grace',
            SystemSettings::TWO_FACTOR_DEADLINE => '2027-01-15',
        ], $root);

        self::assertFalse($settings->dpopRequiredByDefault());
        self::assertFalse($settings->pkceForConfidentialClients());
        self::assertSame('grace', $settings->twoFactorMode());
        self::assertSame('2027-01-15 00:00', $settings->twoFactorDeadline()?->format('Y-m-d H:i'));
        self::assertSame('root', $settings->row(SystemSettings::TWO_FACTOR_MODE)?->getUpdatedBy()?->getUsername());

        // An empty deadline saved by the administrator beats a deadline of the environment.
        $settings->save([SystemSettings::TWO_FACTOR_DEADLINE => ''], $root);
        self::assertNull($settings->twoFactorDeadline());

        $settings->save(array_fill_keys(array_keys($settings->defaults()), null), $root);
        self::assertTrue($settings->dpopRequiredByDefault());
        self::assertSame('required', $settings->twoFactorMode());
        self::assertSame(0, $this->em()->getRepository(Setting::class)->count([]));
    }

    public function testUnchangedValuesAreNotRewritten(): void
    {
        [$root] = $this->createUser('root', 'app-root', withTotp: false);
        $settings = static::getContainer()->get(SystemSettings::class);
        $settings->save([SystemSettings::TWO_FACTOR_MODE => 'grace'], $root);
        $first = $settings->row(SystemSettings::TWO_FACTOR_MODE)?->getUpdatedAt();

        $settings->save([SystemSettings::TWO_FACTOR_MODE => 'grace', SystemSettings::DPOP_REQUIRED => null], $root);

        self::assertEquals($first, $settings->row(SystemSettings::TWO_FACTOR_MODE)?->getUpdatedAt());
        self::assertNull($settings->row(SystemSettings::DPOP_REQUIRED));

        // A value equal to the environment default is not saved: the setting keeps following it.
        $settings->save([SystemSettings::DPOP_REQUIRED => '1', SystemSettings::TWO_FACTOR_DEADLINE => ''], $root);
        self::assertNull($settings->row(SystemSettings::DPOP_REQUIRED));
        self::assertNull($settings->row(SystemSettings::TWO_FACTOR_DEADLINE));
    }
}
