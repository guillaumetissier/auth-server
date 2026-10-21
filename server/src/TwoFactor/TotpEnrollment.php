<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\User;
use App\Event\AccountEvent;
use GuillaumeTissier\OAuthResourceGuard\Webhook\WebhookEvent;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * TOTP enrollment: a secret is proposed, and only stored once the user proves (with a valid
 * code) that the authenticator app has it - no half-configured 2FA locking users out.
 */
final readonly class TotpEnrollment
{
    public function __construct(
        private TotpAuthenticatorInterface $totpAuthenticator,
        private TotpSecretCipher $cipher,
        private BackupCodeGenerator $backupCodes,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private ClockInterface $clock,
        #[Autowire('%env(TOTP_ISSUER)%')] private string $issuer,
    ) {
    }

    public function generateSecret(): string
    {
        return $this->totpAuthenticator->generateSecret();
    }

    /**
     * otpauth:// URI understood by Google Authenticator, Microsoft Authenticator, 2FAS, Aegis...
     */
    public function provisioningUri(User $user, string $secret): string
    {
        $label = rawurlencode($this->issuer).':'.rawurlencode($user->getUsername());

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $this->issuer,
            'algorithm' => strtoupper(User::TOTP_ALGORITHM),
            'digits' => User::TOTP_DIGITS,
            'period' => User::TOTP_PERIOD,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    public function qrCodeDataUri(string $provisioningUri): string
    {
        return (new Builder(writer: new SvgWriter(), data: $provisioningUri, size: 220, margin: 0))->build()->getDataUri();
    }

    public function isValidCode(string $secret, string $code): bool
    {
        return $this->totpAuthenticator->checkCode($this->candidate($secret), $code);
    }

    /**
     * Stores the secret and returns the backup codes, to show to the user exactly once.
     *
     * @return list<string>
     */
    public function enable(User $user, string $secret): array
    {
        $codes = $this->backupCodes->generate();
        $user->enableTotp(
            $this->cipher->encrypt($secret),
            $secret,
            array_map(User::hashBackupCode(...), $codes),
            $this->clock->now(),
        );
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_TWO_FACTOR_ENABLED, $user));

        return $codes;
    }

    /**
     * @return list<string>
     */
    public function regenerateBackupCodes(User $user): array
    {
        $codes = $this->backupCodes->generate();
        $user->replaceBackupCodes(array_map(User::hashBackupCode(...), $codes));
        $this->em->flush();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->disableTotp();
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_TWO_FACTOR_DISABLED, $user));
    }

    private function candidate(string $secret): TwoFactorInterface
    {
        return new class($secret) implements TwoFactorInterface {
            public function __construct(private readonly string $secret)
            {
            }

            public function isTotpAuthenticationEnabled(): bool
            {
                return true;
            }

            public function getTotpAuthenticationUsername(): string
            {
                return '';
            }

            public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
            {
                return new TotpConfiguration($this->secret, User::TOTP_ALGORITHM, User::TOTP_PERIOD, User::TOTP_DIGITS);
            }
        };
    }
}
