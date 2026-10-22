<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:resource-type', description: 'Lists, declares or removes the resource types used in access expressions')]
final readonly class ResourceTypeCommand
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Type to declare or remove (empty: list)')] ?string $code = null,
        #[Argument('Label')] ?string $label = null,
        #[Option('Remove the type')] bool $remove = false,
    ): int {
        $repository = $this->em->getRepository(ResourceType::class);
        if (null === $code) {
            $rows = array_map(static fn (ResourceType $t): array => [$t->getCode(), $t->getLabel()], $repository->findBy([], ['code' => 'ASC']));
            foreach (ResourceType::RESERVED as $reserved => $description) {
                $rows[] = [$reserved, $description.' (built in)'];
            }
            $io->table(['Type', 'Label'], $rows);

            return Command::SUCCESS;
        }

        $type = $repository->find($code);
        if ($remove) {
            if (null !== $type) {
                $this->em->remove($type);
                $this->em->flush();
            }
            $io->success(\sprintf('Resource type "%s" removed. Expressions naming it are refused from now on until it is declared again.', $code));

            return Command::SUCCESS;
        }

        try {
            null === $type ? $this->em->persist(new ResourceType($code, $label ?? $code)) : $type->setLabel($label ?? $type->getLabel());
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $this->em->flush();
        $io->success(\sprintf('Resource type "%s" declared.', $code));

        return Command::SUCCESS;
    }
}
