<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Credentials only. Everything else about the user lives in the main application database,
 * referenced by $externalUserId (which becomes the "sub" claim of the access tokens).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface, BackupCodeInterface
{
    /** Can impersonate regular users, read-only. */
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    /** Can impersonate anyone but another super-admin, with full rights. Held by one employee only. */
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    public const TOTP_ALGORITHM = TotpConfiguration::ALGORITHM_SHA1; // the only one every authenticator app supports
    public const TOTP_PERIOD = 30;
    public const TOTP_DIGITS = 6;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $username;

    /** Argon2id/bcrypt hash: the salt is part of the hash string. */
    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $password;

    #[ORM\Column(name: 'external_user_id', length: 64, unique: true)]
    private string $externalUserId;

    /** @var list<string> roles besides ROLE_USER (ROLE_ADMIN, ROLE_SUPER_ADMIN) */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** TOTP secret encrypted with TOTP_ENCRYPTION_KEY (see TotpSecretCipher). */
    #[ORM\Column(name: 'totp_secret', type: Types::TEXT, nullable: true)]
    private ?string $encryptedTotpSecret = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    /** @var list<string> SHA-256 hashes of the unused backup codes */
    #[ORM\Column(type: Types::JSON)]
    private array $backupCodes = [];

    /** How many times the user chose "Later" on the 2FA enrollment page (grace period). */
    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $twoFactorPostponeCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $twoFactorPostponedAt = null;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $failedLoginAttempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Decrypted TOTP secret, filled by TotpSecretLoadListener. Never persisted. */
    private ?string $plainTotpSecret = null;

    public function __construct(string $username, string $externalUserId)
    {
        $this->username = $username;
        $this->externalUserId = $externalUserId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getExternalUserId(): string
    {
        return $this->externalUserId;
    }

    public function getRoles(): array
    {
        return array_values(array_unique(['ROLE_USER', ...$this->roles]));
    }

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    public function addRole(string $role): void
    {
        if (!$this->hasRole($role)) {
            $this->roles[] = $role;
        }
    }

    public function removeRole(string $role): void
    {
        $this->roles = array_values(array_diff($this->roles, [$role]));
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN) || $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Only a hash of the password goes into the serialized session.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);
        unset($data["\0".self::class."\0plainTotpSecret"]);

        return $data;
    }

    // --- Account status -----------------------------------------------------------------

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isLocked(\DateTimeImmutable $now): bool
    {
        return null !== $this->lockedUntil && $this->lockedUntil > $now;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    /**
     * @return bool true if this failure locked the account
     */
    public function registerFailedLogin(\DateTimeImmutable $now, int $maxAttempts, \DateInterval $lockDuration): bool
    {
        ++$this->failedLoginAttempts;

        if ($this->failedLoginAttempts >= $maxAttempts && !$this->isLocked($now)) {
            $this->lockedUntil = $now->add($lockDuration);
            $this->failedLoginAttempts = 0;

            return true;
        }

        return false;
    }

    public function registerSuccessfulLogin(\DateTimeImmutable $now): void
    {
        $this->failedLoginAttempts = 0;
        $this->lockedUntil = null;
        $this->lastLoginAt = $now;
    }

    public function getFailedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    // --- TOTP -------------------------------------------------------------------------

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpEnabledAt && null !== $this->plainTotpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->username;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->plainTotpSecret) {
            return null;
        }

        return new TotpConfiguration($this->plainTotpSecret, self::TOTP_ALGORITHM, self::TOTP_PERIOD, self::TOTP_DIGITS);
    }

    public function getEncryptedTotpSecret(): ?string
    {
        return $this->encryptedTotpSecret;
    }

    public function hydratePlainTotpSecret(?string $plainSecret): void
    {
        $this->plainTotpSecret = $plainSecret;
    }

    /**
     * @param list<string> $backupCodeHashes
     */
    public function enableTotp(string $encryptedSecret, string $plainSecret, array $backupCodeHashes, \DateTimeImmutable $now): void
    {
        $this->encryptedTotpSecret = $encryptedSecret;
        $this->plainTotpSecret = $plainSecret;
        $this->totpEnabledAt = $now;
        $this->backupCodes = $backupCodeHashes;
    }

    public function disableTotp(): void
    {
        $this->encryptedTotpSecret = null;
        $this->plainTotpSecret = null;
        $this->totpEnabledAt = null;
        $this->backupCodes = [];
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    public function postponeTwoFactor(\DateTimeImmutable $now): void
    {
        ++$this->twoFactorPostponeCount;
        $this->twoFactorPostponedAt = $now;
    }

    public function getTwoFactorPostponeCount(): int
    {
        return $this->twoFactorPostponeCount;
    }

    public function getTwoFactorPostponedAt(): ?\DateTimeImmutable
    {
        return $this->twoFactorPostponedAt;
    }

    // --- Backup codes -----------------------------------------------------------------

    public static function hashBackupCode(string $code): string
    {
        return hash('sha256', strtoupper(str_replace([' ', '-'], '', $code)));
    }

    public function isBackupCode(string $code): bool
    {
        $hash = self::hashBackupCode($code);
        foreach ($this->backupCodes as $stored) {
            if (hash_equals($stored, $hash)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $hash = self::hashBackupCode($code);
        $this->backupCodes = array_values(array_filter($this->backupCodes, static fn (string $s): bool => !hash_equals($s, $hash)));
    }

    /**
     * @param list<string> $backupCodeHashes
     */
    public function replaceBackupCodes(array $backupCodeHashes): void
    {
        $this->backupCodes = $backupCodeHashes;
    }

    public function countBackupCodes(): int
    {
        return \count($this->backupCodes);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }
}
