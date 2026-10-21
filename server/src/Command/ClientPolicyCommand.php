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

#[AsCommand(name: 'app:client:policy', description: 'Sets whether an OAuth client must use DPoP-bound tokens')]
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
    ): int {
        if ($requireDpop === $allowBearer) {
            $io->error('Pass exactly one of --require-dpop or --allow-bearer.');

            return Command::INVALID;
        }

        $clientEntity = $this->em->find(Client::class, $client);
        if (null === $clientEntity) {
            $io->error(\sprintf('Unknown client "%s" (create it with league:oauth2-server:create-client).', $client));

            return Command::FAILURE;
        }

        $policy = $this->em->getRepository(ClientPolicy::class)->findOneBy(['client' => $client]);
        if (null === $policy) {
            $this->em->persist(new ClientPolicy($clientEntity, $requireDpop));
        } else {
            $policy->setRequireDpop($requireDpop);
        }
        $this->em->flush();

        $io->success(\sprintf('Client "%s": DPoP %s.', $client, $requireDpop ? 'required' : 'optional'));

        return Command::SUCCESS;
    }
}
