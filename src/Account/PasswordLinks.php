<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Event\AccountEvent;
use App\I18n\Locales;
use App\I18n\Text;
use App\Repository\UserRepository;
use App\Settings\SystemSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Single-use links to choose a password, sent by e-mail:
 *  - reset: "Forgot your password?", or sent by an administrator;
 *  - invitation: a new account created in the administration pages, which has no password yet.
 *
 * Only a SHA-256 of the link's secret is stored. A new link replaces the previous one, and a link
 * dies once used or expired.
 */
final readonly class PasswordLinks
{
    public const RESET = 'reset';
    public const INVITATION = 'invitation';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private EventDispatcherInterface $dispatcher,
        private LocaleSwitcher $localeSwitcher,
        private TranslatorInterface $translator,
        private SystemSettings $settings,
        private string $issuer,
        #[Autowire('%env(PASSWORD_RESET_TTL)%')] private string $resetTtl,
        #[Autowire('%env(PASSWORD_INVITATION_TTL)%')] private string $invitationTtl,
    ) {
    }

    /**
     * Creates a link for $user and e-mails it. The user must have an e-mail address.
     *
     * @param self::RESET|self::INVITATION $purpose
     */
    public function send(User $user, string $purpose, ?User $actor = null): void
    {
        $email = $user->getEmail() ?? throw new \LogicException('The user has no e-mail address.');

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $this->clock->now()->add(new \DateInterval(self::INVITATION === $purpose ? $this->invitationTtl : $this->resetTtl));
        $user->setPasswordLink(self::hash($secret), $expiresAt);
        $this->em->flush();

        // In the recipient's language, not in the language of whoever sends it (an administrator).
        $locale = Locales::isEnabled($user->getLocale()) ? (string) $user->getLocale() : $this->settings->defaultLocale();
        $this->localeSwitcher->runWithLocale($locale, fn () => $this->mailer->send((new TemplatedEmail())
            ->to(new Address($email, $user->getUsername()))
            ->subject((self::INVITATION === $purpose ? new Text('email.password_link.new_account') : new Text('email.password_link.choose_new_password'))->trans($this->translator, $locale))
            ->htmlTemplate('email/password_link.html.twig')
            ->textTemplate('email/password_link.txt.twig')
            ->context([
                'purpose' => $purpose,
                'username' => $user->getUsername(),
                'url' => rtrim($this->issuer, '/').'/password/set/'.$secret,
                'expires_at' => $expiresAt,
            ])));

        $this->dispatcher->dispatch(new AccountEvent(AccountEvent::USER_PASSWORD_LINK_SENT, $user, [
            'purpose' => $purpose,
            'actor_id' => $actor?->getExternalUserId(),
        ]));
    }

    /**
     * The enabled user whose valid link this is, or null.
     */
    public function userFor(string $secret): ?User
    {
        if ('' === $secret || \strlen($secret) > 100) {
            return null;
        }
        $user = $this->users->findOneBy(['passwordLinkHash' => self::hash($secret)]);

        return null !== $user && $user->isEnabled() && $user->hasValidPasswordLink($this->clock->now()) ? $user : null;
    }

    private static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
