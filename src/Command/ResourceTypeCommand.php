<?php

declare(strict_types=1);

namespace App\Command;

use App\Access\ResourceCatalog;
use App\Entity\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resource types and facets from the console:
 *
 *   app:resource-type                                        lists types, facets and where they are used
 *   app:resource-type vehicle Vehicles                       declares a type (or changes its label)
 *   app:resource-type vehicle.pricing Prices --description="Sale price, discount"   declares a facet
 *   app:resource-type vehicle.pricing --remove               removes a facet no right names any more
 */
#[AsCommand(name: 'app:resource-type', description: 'Lists, declares or removes the resource types and facets used in access expressions')]
final readonly class ResourceTypeCommand
{
    public function __construct(
        private ResourceCatalog $catalog,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Type ("vehicle") or facet ("vehicle.pricing") to declare or remove (empty: list)')] ?string $code = null,
        #[Argument('Label')] ?string $label = null,
        #[Option('What a facet covers, for administrators')] ?string $description = null,
        #[Option('Remove the type or facet')] bool $remove = false,
    ): int {
        if (null === $code) {
            $usage = $this->catalog->usageCounts();
            $rows = [];
            foreach ($this->catalog->types() as $type) {
                $rows[] = [$type->getCode(), $type->getLabel(), '', $usage[$type->getCode()] ?? 0];
                foreach ($type->getFacets() as $facet) {
                    $rows[] = ['  '.$facet->getCode(), $facet->getLabel(), $facet->getDescription() ?? '', $usage[$facet->getCode()] ?? 0];
                }
            }
            foreach (ResourceType::RESERVED as $reserved => $text) {
                $rows[] = [$reserved, $text.' (built in)', '', ''];
            }
            $io->table(['Type or facet', 'Label', 'Description', 'Used in'], $rows);

            return Command::SUCCESS;
        }

        try {
            if ($remove) {
                $this->catalog->remove($code);
            } else {
                // Options left out keep their current value.
                [$currentLabel, $currentDescription] = $this->current($code);
                $this->catalog->declare($code, $label ?? $currentLabel ?? $code, $description ?? $currentDescription);
            }
        } catch (\InvalidArgumentException|\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $this->em->flush();
        $io->success(\sprintf('"%s" %s.', strtolower($code), $remove ? 'removed' : 'saved'));

        return Command::SUCCESS;
    }

    /**
     * Label and description of an existing type or facet, nulls for a new one.
     *
     * @return array{?string, ?string}
     */
    private function current(string $code): array
    {
        foreach ($this->catalog->types() as $type) {
            if ($type->getCode() === strtolower($code)) {
                return [$type->getLabel(), null];
            }
            foreach ($type->getFacets() as $facet) {
                if ($facet->getCode() === strtolower($code)) {
                    return [$facet->getLabel(), $facet->getDescription()];
                }
            }
        }

        return [null, null];
    }
}
