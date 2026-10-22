<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ClientPolicy;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:client:policy', description: 'Per-client settings: DPoP requirement, impersonation entry point')]
final readonly class ClientPolicyCommand
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Client identifier')] string $client,
        #[Option('Require DPoP proofs at the token endpoint')] bool $requireDpop = false,
        #[Option('Allow Bearer tokens (no DPoP) for this client')] bool $allowBearer = false,
        #[Option('Follow DPOP_REQUIRED_BY_DEFAULT')] bool $defaultDpop = false,
        #[Option('URL where the application starts an impersonation, with {user}; "none" to remove')] ?string $impersonationUri = null,
    ): int {
        if ((int) $requireDpop + (int) $allowBearer + (int) $defaultDpop > 1) {
            $io->error('Pass at most one of --require-dpop, --allow-bearer and --default-dpop.');

            return Command::INVALID;
        }

        $clientEntity = $this->em->find(Client::class, $client);
        if (null === $clientEntity) {
            $io->error(\sprintf('Unknown client "%s" (create it with league:oauth2-server:create-client).', $client));

            return Command::FAILURE;
        }

        $policy = $this->em->getRepository(ClientPolicy::class)->findOneBy(['client' => $client]);
        if (null === $policy) {
            $policy = new ClientPolicy($clientEntity);
            $this->em->persist($policy);
        }
        if ($requireDpop || $allowBearer || $defaultDpop) {
            $policy->setRequireDpop($defaultDpop ? null : $requireDpop);
        }
        if (null !== $impersonationUri) {
            try {
                $policy->setImpersonationStartUri('none' === $impersonationUri ? null : $impersonationUri);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }
        }
        $this->em->flush();

        $io->success(\sprintf(
            'Client "%s": DPoP %s, impersonation entry point: %s.',
            $client,
            match ($policy->requiresDpop()) {
                true => 'required',
                false => 'optional',
                null => 'as DPOP_REQUIRED_BY_DEFAULT',
            },
            $policy->getImpersonationStartUri() ?? 'none',
        ));

        return Command::SUCCESS;
    }
}
