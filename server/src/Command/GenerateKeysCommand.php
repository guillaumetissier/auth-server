<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Generates the RSA key pair signing access tokens (run once; the container entrypoint
 * calls it when the keys are missing).
 */
#[AsCommand(name: 'app:keys:generate', description: 'Generates the RSA key pair used to sign access tokens')]
final readonly class GenerateKeysCommand
{
    public function __construct(
        private string $privateKeyPath,
        private string $publicKeyPath,
        #[Autowire('%env(default::OAUTH_PASSPHRASE)%')] private ?string $passphrase,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only generate when the keys do not exist yet')] bool $ifMissing = false,
        #[Option('Overwrite existing keys (invalidates every issued token)')] bool $overwrite = false,
    ): int {
        $exists = is_file($this->privateKeyPath) || is_file($this->publicKeyPath);

        if ($exists && $ifMissing) {
            $io->writeln('Keys already exist, nothing to do.');

            return Command::SUCCESS;
        }
        if ($exists && !$overwrite) {
            $io->error('Keys already exist. Use --overwrite to replace them (every issued token becomes invalid).');

            return Command::FAILURE;
        }

        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $key || !openssl_pkey_export($key, $privatePem, '' !== (string) $this->passphrase ? $this->passphrase : null)) {
            $io->error('Unable to generate the key pair: '.openssl_error_string());

            return Command::FAILURE;
        }
        $publicPem = openssl_pkey_get_details($key)['key'];

        $fs = new Filesystem();
        $fs->mkdir(\dirname($this->privateKeyPath), 0700);
        $fs->dumpFile($this->privateKeyPath, $privatePem);
        $fs->chmod($this->privateKeyPath, 0600);
        $fs->mkdir(\dirname($this->publicKeyPath), 0755);
        $fs->dumpFile($this->publicKeyPath, $publicPem);
        $fs->chmod($this->publicKeyPath, 0644);

        $io->success('RSA key pair generated.');

        return Command::SUCCESS;
    }
}
