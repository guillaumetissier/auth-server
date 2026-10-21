<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports existing accounts from the main application, keeping their password hashes:
 * nobody has to choose a new password. bcrypt hashes (Laravel's default, "$2y$…") are verified
 * as they are and transparently rehashed to Argon2id at the first successful login
 * (UserRepository::upgradePassword).
 *
 * CSV with a header row: username,external_user_id,password_hash
 * Idempotent: rows whose username or external id already exist are skipped.
 */
#[AsCommand(name: 'app:user:import', description: 'Imports existing users (username, external_user_id, password_hash) from a CSV file')]
final readonly class ImportUsersCommand
{
    private const COLUMNS = ['username', 'external_user_id', 'password_hash'];
    private const BATCH_SIZE = 200;

    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private LoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path of the CSV file')] string $file,
        #[Option('Field delimiter')] string $delimiter = ',',
        #[Option('Validate and report without writing anything')] bool $dryRun = false,
    ): int {
        if (!is_file($file) || !is_readable($file)) {
            $io->error(\sprintf('Cannot read "%s".', $file));

            return Command::FAILURE;
        }

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, null, $delimiter, '"', '');
        $header = \is_array($header) ? array_map(static fn ($h): string => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), $header) : [];
        if (array_diff(self::COLUMNS, $header) !== []) {
            $io->error(\sprintf('The first line must name the columns: %s.', implode(',', self::COLUMNS)));

            return Command::FAILURE;
        }
        $index = array_flip($header);

        // Existing accounts, loaded once (the auth server holds credentials only: a few thousand rows at most).
        $knownUsernames = [];
        $knownExternalIds = [];
        foreach ($this->em->createQuery('SELECT u.username, u.externalUserId FROM '.User::class.' u')->toIterable() as $row) {
            $knownUsernames[mb_strtolower($row['username'])] = true;
            $knownExternalIds[$row['externalUserId']] = true;
        }

        $created = 0;
        $skipped = [];
        $invalid = [];
        $line = 1;
        while (false !== ($row = fgetcsv($handle, null, $delimiter, '"', ''))) {
            ++$line;
            if ([null] === $row) {
                continue; // blank line
            }

            $username = trim((string) ($row[$index['username']] ?? ''));
            $externalId = trim((string) ($row[$index['external_user_id']] ?? ''));
            $hash = trim((string) ($row[$index['password_hash']] ?? ''));

            if ('' === $username || '' === $externalId || mb_strlen($username) > 180 || mb_strlen($externalId) > 64) {
                $invalid[] = [$line, $username, 'missing or too long username / external_user_id'];
                continue;
            }
            if (!self::isSupportedHash($hash)) {
                // Never import a clear-text password or an unknown format.
                $invalid[] = [$line, $username, 'password_hash is not a bcrypt or Argon2 hash'];
                continue;
            }
            if (isset($knownUsernames[mb_strtolower($username)]) || isset($knownExternalIds[$externalId])) {
                $skipped[] = [$line, $username, 'already exists'];
                continue;
            }

            $knownUsernames[mb_strtolower($username)] = true;
            $knownExternalIds[$externalId] = true;
            ++$created;

            if ($dryRun) {
                continue;
            }

            $user = new User($username, $externalId);
            $user->setPassword($hash);
            $this->em->persist($user);
            if (0 === $created % self::BATCH_SIZE) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        fclose($handle);

        if (!$dryRun) {
            $this->em->flush();
            $this->auditLogger->info('users.imported', ['created' => $created, 'skipped' => \count($skipped), 'invalid' => \count($invalid)]);
        }

        if ([] !== $invalid) {
            $io->warning(\sprintf('%d invalid line(s), not imported:', \count($invalid)));
            $io->table(['Line', 'Username', 'Problem'], $invalid);
        }
        if ([] !== $skipped && $io->isVerbose()) {
            $io->table(['Line', 'Username', 'Skipped'], $skipped);
        }

        $io->success(\sprintf(
            '%s%d account(s) created, %d skipped (already present), %d invalid.',
            $dryRun ? '[dry run] ' : '',
            $created,
            \count($skipped),
            \count($invalid),
        ));

        return [] === $invalid ? Command::SUCCESS : Command::FAILURE;
    }

    private static function isSupportedHash(string $hash): bool
    {
        $info = password_get_info($hash);

        return \in_array($info['algoName'], ['bcrypt', 'argon2i', 'argon2id'], true);
    }
}
