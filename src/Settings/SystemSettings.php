<?php

declare(strict_types=1);

namespace App\Settings;

use App\Entity\Setting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * System settings the super-administrator changes in the administration pages. A value saved there
 * takes precedence over the environment variable (.env.docker), which stays the default.
 */
final class SystemSettings implements ResetInterface
{
    /** Clients must send DPoP proofs, unless their own policy says otherwise. */
    public const DPOP_REQUIRED = 'dpop.required_by_default';
    /** PKCE (S256) also for confidential clients (with a secret). Public clients always need it. */
    public const PKCE_CONFIDENTIAL = 'pkce.confidential_clients';
    /** optional | grace | required */
    public const TWO_FACTOR_MODE = 'two_factor.mode';
    /** Date from which 2FA is required in grace mode (Y-m-d), empty: not decided. */
    public const TWO_FACTOR_DEADLINE = 'two_factor.deadline';

    /** Lifetimes, as ISO 8601 durations. */
    public const ACCESS_TOKEN_TTL = 'tokens.access_ttl';
    public const REFRESH_TOKEN_TTL = 'tokens.refresh_ttl';
    public const IMPERSONATION_TTL = 'impersonation.session_ttl';
    public const IMPERSONATION_ACCESS_TOKEN_TTL = 'impersonation.access_ttl';

    public const TWO_FACTOR_MODES = ['optional', 'grace', 'required'];

    /**
     * Allowed range of each lifetime, in seconds: [min, max].
     *
     * @var array<string, array{int, int}>
     */
    public const LIFETIME_BOUNDS = [
        self::ACCESS_TOKEN_TTL => [60, 86400],                  // 1 minute to 1 day
        self::REFRESH_TOKEN_TTL => [3600, 365 * 86400],         // 1 hour to 1 year
        self::IMPERSONATION_TTL => [300, 8 * 3600],             // 5 minutes to 8 hours
        self::IMPERSONATION_ACCESS_TOKEN_TTL => [60, 3600],     // 1 minute to 1 hour
    ];

    /** @var array<string, Setting>|null */
    private ?array $rows = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $auditLogger,
        #[Autowire('%env(bool:DPOP_REQUIRED_BY_DEFAULT)%')] private readonly bool $envDpopRequired,
        #[Autowire('%env(bool:PKCE_FOR_CONFIDENTIAL_CLIENTS)%')] private readonly bool $envPkceConfidential,
        #[Autowire('%env(APP_2FA_MODE)%')] private readonly string $envTwoFactorMode,
        #[Autowire('%env(default::APP_2FA_DEADLINE)%')] private readonly ?string $envTwoFactorDeadline,
        #[Autowire('%env(OAUTH_ACCESS_TOKEN_TTL)%')] private readonly string $envAccessTokenTtl,
        #[Autowire('%env(OAUTH_REFRESH_TOKEN_TTL)%')] private readonly string $envRefreshTokenTtl,
        #[Autowire('%env(IMPERSONATION_TTL)%')] private readonly string $envImpersonationTtl,
        #[Autowire('%env(IMPERSONATION_ACCESS_TOKEN_TTL)%')] private readonly string $envImpersonationAccessTokenTtl,
    ) {
    }

    public function dpopRequiredByDefault(): bool
    {
        return $this->bool(self::DPOP_REQUIRED, $this->envDpopRequired);
    }

    public function pkceForConfidentialClients(): bool
    {
        return $this->bool(self::PKCE_CONFIDENTIAL, $this->envPkceConfidential);
    }

    public function twoFactorMode(): string
    {
        $mode = $this->row(self::TWO_FACTOR_MODE)?->getValue() ?? $this->envTwoFactorMode;
        if (!\in_array($mode, self::TWO_FACTOR_MODES, true)) {
            throw new \InvalidArgumentException(\sprintf('APP_2FA_MODE must be optional, grace or required, "%s" given.', $mode));
        }

        return $mode;
    }

    public function twoFactorDeadline(): ?\DateTimeImmutable
    {
        $row = $this->row(self::TWO_FACTOR_DEADLINE);
        $value = trim((string) (null !== $row ? $row->getValue() : $this->envTwoFactorDeadline));
        if ('' === $value) {
            return null;
        }
        try {
            // A bare date ("2026-12-31") means: required from that day on, 00:00 in the server time zone.
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(\sprintf('APP_2FA_DEADLINE must be a date such as 2026-12-31, "%s" given.', $value));
        }
    }

    /**
     * A lifetime (access, refresh, impersonation…): the saved value, otherwise the environment's.
     */
    public function lifetime(string $name): \DateInterval
    {
        return new \DateInterval($this->lifetimeIso($name));
    }

    public function lifetimeIso(string $name): string
    {
        return $this->row($name)?->getValue() ?? $this->defaults()[$name] ?? throw new \InvalidArgumentException('Unknown lifetime '.$name);
    }

    /**
     * The saved row of a setting, null when the environment default applies.
     */
    public function row(string $name): ?Setting
    {
        $this->rows ??= $this->load();

        return $this->rows[$name] ?? null;
    }

    /**
     * Saves the given values (null: back to the environment default) and logs what changed.
     *
     * @param array<string, string|null> $values setting name => value
     */
    public function save(array $values, User $actor): void
    {
        $now = $this->clock->now();
        $defaults = $this->defaults();
        foreach ($values as $name => $value) {
            $row = $this->row($name);
            if ($row?->getValue() === $value && (null !== $row || null === $value)) {
                continue;
            }
            // Same as the environment default and never changed: keep following the default.
            if (null === $row && $value === ($defaults[$name] ?? null)) {
                continue;
            }
            $this->auditLogger->info('settings.changed', [
                'setting' => $name,
                'from' => $row?->getValue() ?? '(default)',
                'to' => $value ?? '(default)',
                'actor' => $actor->getUsername(),
                'actor_id' => $actor->getExternalUserId(),
            ]);
            if (null === $value) {
                if (null !== $row) {
                    $this->em->remove($row);
                }
            } elseif (null === $row) {
                $this->em->persist(new Setting($name, $value, $actor, $now));
            } else {
                $row->change($value, $actor, $now);
            }
        }
        $this->em->flush();
        $this->reset();
    }

    /**
     * The environment defaults, for display.
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            self::DPOP_REQUIRED => $this->envDpopRequired ? '1' : '0',
            self::PKCE_CONFIDENTIAL => $this->envPkceConfidential ? '1' : '0',
            self::TWO_FACTOR_MODE => $this->envTwoFactorMode,
            self::TWO_FACTOR_DEADLINE => (string) $this->envTwoFactorDeadline,
            self::ACCESS_TOKEN_TTL => $this->envAccessTokenTtl,
            self::REFRESH_TOKEN_TTL => $this->envRefreshTokenTtl,
            self::IMPERSONATION_TTL => $this->envImpersonationTtl,
            self::IMPERSONATION_ACCESS_TOKEN_TTL => $this->envImpersonationAccessTokenTtl,
        ];
    }

    public function reset(): void
    {
        $this->rows = null;
    }

    private function bool(string $name, bool $default): bool
    {
        $value = $this->row($name)?->getValue();

        return null === $value ? $default : '1' === $value;
    }

    /**
     * @return array<string, Setting>
     */
    private function load(): array
    {
        $rows = [];
        foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
            $rows[$setting->getName()] = $setting;
        }

        return $rows;
    }
}
