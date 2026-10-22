<?php

declare(strict_types=1);

namespace App\Command;

use App\Access\AccessManager;
use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\InvalidAccessExpression;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports domains and rights from another system, once (the auth server is the master afterwards).
 * Users must exist already (app:user:import). The file is checked completely before anything is
 * written, and written in one transaction. Existing entries are updated, nothing is deleted.
 *
 * {
 *   "resource_types": [{"code": "vehicle", "label": "Vehicles"}],
 *   "domains":  [{"code": "a1", "name": "Group A", "parent": null, "owner": "alice", "owner_rights": "*:m @access:w @domain:w"}],
 *   "members":  [{"user": "alice", "domain": "a1"}],
 *   "grants":   [{"user": "bob", "domain": "a10", "subdomains": true, "rights": "*:r vehicle:w user:w:own"}]
 * }
 *
 * Users are referenced by username ("user") or by their id in the main application ("external_user_id").
 *
 * @phpstan-type TypeRow array{code: string, label: string}
 * @phpstan-type DomainRow array{code: string, name: string, parent: ?string, setsOwner: bool, owner: ?User, ownerRights: ?AccessExpression}
 * @phpstan-type MemberRow array{user: User, domain: ?string}
 * @phpstan-type GrantRow array{user: User, domain: string, subdomains: bool, rights: AccessExpression}
 */
#[AsCommand(name: 'app:access:import', description: 'Imports domains, memberships, owners and rights from a JSON file')]
final class AccessImportCommand
{
    /** @var list<string> */
    private array $errors = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DomainRepository $domains,
        private readonly UserRepository $users,
        private readonly AccessManager $manager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('JSON file')] string $file,
        #[Option('Check the file and report, write nothing')] bool $dryRun = false,
    ): int {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!\is_array($data)) {
            $io->error(\sprintf('"%s" is not a readable JSON file.', $file));

            return Command::FAILURE;
        }
        $this->errors = [];

        // --- Read and check everything first: typed rows, or errors.
        $knownTypes = [];
        foreach ($this->em->getRepository(ResourceType::class)->findAll() as $type) {
            $knownTypes[$type->getCode()] = true;
        }
        $types = $this->resourceTypes($this->items($data, 'resource_types'));
        foreach ($types as $type) {
            $knownTypes[$type['code']] = true;
        }

        $paths = [];
        foreach ($this->domains->findAll() as $existing) {
            $paths[$existing->getCode()] = $existing->getPath();
        }
        $domains = $this->domainRows($this->sortByParent($this->items($data, 'domains')), $paths, $knownTypes);
        $members = $this->memberRows($this->items($data, 'members'), $paths);

        $memberPath = static function (User $user) use ($members, $paths): ?string {
            foreach ($members as $member) {
                if ($member['user']->getId() === $user->getId()) {
                    return null === $member['domain'] ? null : $paths[$member['domain']];
                }
            }

            return $user->getDomain()?->getPath();
        };

        foreach ($domains as $domain) {
            if (null !== $domain['owner'] && null !== ($path = $memberPath($domain['owner'])) && !str_starts_with($paths[$domain['code']], $path)) {
                $this->errors[] = \sprintf('domain "%s": owner %s belongs to %s, not to this domain or above.', $domain['code'], $domain['owner']->getUsername(), $path);
            }
        }

        $grants = $this->grantRows($this->items($data, 'grants'), $paths, $knownTypes);
        foreach ($grants as $i => $grant) {
            $path = $memberPath($grant['user']);
            if (null !== $path && !str_starts_with($paths[$grant['domain']], $path)) {
                $this->errors[] = \sprintf('grants[%d]: %s belongs to %s, rights on %s would never apply.', $i, $grant['user']->getUsername(), $path, $paths[$grant['domain']]);
            }
        }

        if ([] !== $this->errors) {
            $io->error(\sprintf('%d problem(s), nothing imported:', \count($this->errors)));
            $io->listing(\array_slice($this->errors, 0, 50));

            return Command::FAILURE;
        }

        $summary = \sprintf('%d resource type(s), %d domain(s), %d membership(s), %d grant(s)', \count($types), \count($domains), \count($members), \count($grants));
        if ($dryRun) {
            $io->success('Dry run, the file is valid: '.$summary.'.');

            return Command::SUCCESS;
        }

        // --- Write, in one transaction.
        $this->em->wrapInTransaction(function () use ($types, $domains, $members, $grants): void {
            foreach ($types as $type) {
                $existing = $this->em->find(ResourceType::class, $type['code']);
                null === $existing ? $this->em->persist(new ResourceType($type['code'], $type['label'])) : $existing->setLabel($type['label']);
            }
            $this->em->flush();

            foreach ($domains as $row) {
                $domain = $this->domains->findByCode($row['code']);
                if (null === $domain) {
                    $this->manager->createDomain($row['code'], $row['name'], null === $row['parent'] ? null : $this->domain($row['parent']));
                } else {
                    $domain->setName($row['name']);
                }
            }
            $this->em->flush();

            foreach ($members as $member) {
                $this->manager->moveUser($member['user'], null === $member['domain'] ? null : $this->domain($member['domain']), null);
            }

            foreach ($domains as $row) {
                if (!$row['setsOwner'] && null === $row['ownerRights']) {
                    continue;
                }
                $domain = $this->domain($row['code']);
                $this->manager->setOwner(
                    $domain,
                    $row['setsOwner'] ? $row['owner'] : $domain->getOwner(),
                    $row['ownerRights'] ?? $domain->getOwnerExpression(),
                    null,
                );
            }

            foreach ($grants as $grant) {
                $this->manager->grant($grant['user'], $this->domain($grant['domain']), $grant['rights'], $grant['subdomains'], null);
            }
        });

        $io->success('Imported '.$summary.'.');

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<TypeRow>
     */
    private function resourceTypes(array $items): array
    {
        $rows = [];
        foreach ($items as $i => $item) {
            $code = self::str($item, 'code');
            if (null === $code || 1 !== preg_match(ResourceType::CODE_PATTERN, $code)) {
                $this->errors[] = \sprintf('resource_types[%d]: invalid code.', $i);
                continue;
            }
            $rows[] = ['code' => $code, 'label' => self::str($item, 'label') ?? $code];
        }

        return $rows;
    }

    /**
     * Also completes $paths with the domains of the file.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $paths      domain code => path
     * @param array<string, true>        $knownTypes
     *
     * @return list<DomainRow>
     */
    private function domainRows(array $items, array &$paths, array $knownTypes): array
    {
        $rows = [];
        foreach ($items as $i => $item) {
            $code = self::str($item, 'code');
            $parent = self::str($item, 'parent');
            if (null === $code || 1 !== preg_match(Domain::CODE_PATTERN, $code)) {
                $this->errors[] = \sprintf('domains[%d]: invalid code.', $i);
                continue;
            }
            if (null !== $parent && !isset($paths[$parent])) {
                $this->errors[] = \sprintf('domain "%s": unknown parent "%s".', $code, $parent);
                continue;
            }
            $path = (null === $parent ? '/' : $paths[$parent]).$code.'/';
            if (isset($paths[$code]) && $paths[$code] !== $path) {
                $this->errors[] = \sprintf('domain "%s" exists at %s, the file puts it at %s: moving domains is not supported.', $code, $paths[$code], $path);
            }
            $paths[$code] = $path;

            $setsOwner = \array_key_exists('owner', $item);
            $ownerName = self::str($item, 'owner');
            $rows[] = [
                'code' => $code,
                'name' => self::str($item, 'name') ?? $code,
                'parent' => $parent,
                'setsOwner' => $setsOwner,
                'owner' => null === $ownerName ? null : $this->user(['user' => $ownerName], \sprintf('domain "%s" owner', $code)),
                'ownerRights' => \array_key_exists('owner_rights', $item) ? $this->expression($item['owner_rights'], \sprintf('domain "%s" owner_rights', $code), $knownTypes) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $paths
     *
     * @return list<MemberRow>
     */
    private function memberRows(array $items, array $paths): array
    {
        $rows = [];
        foreach ($items as $i => $item) {
            $label = \sprintf('members[%d]', $i);
            $user = $this->user($item, $label);
            $domain = self::str($item, 'domain');
            if (null !== $domain && !isset($paths[$domain])) {
                $this->errors[] = \sprintf('%s: unknown domain "%s".', $label, $domain);
                continue;
            }
            if (null !== $user) {
                $rows[] = ['user' => $user, 'domain' => $domain];
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $paths
     * @param array<string, true>        $knownTypes
     *
     * @return array<int, GrantRow> keyed by position in the file
     */
    private function grantRows(array $items, array $paths, array $knownTypes): array
    {
        $rows = [];
        foreach ($items as $i => $item) {
            $label = \sprintf('grants[%d]', $i);
            $user = $this->user($item, $label);
            $domain = self::str($item, 'domain');
            if (null === $domain || !isset($paths[$domain])) {
                $this->errors[] = \sprintf('%s: unknown domain "%s".', $label, $domain ?? '');
                continue;
            }
            $rights = $this->expression($item['rights'] ?? null, $label, $knownTypes);
            if (null !== $user && null !== $rights) {
                $rows[$i] = ['user' => $user, 'domain' => $domain, 'subdomains' => true === ($item['subdomains'] ?? false), 'rights' => $rights];
            }
        }

        return $rows;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $data, string $key): array
    {
        $items = $data[$key] ?? [];
        if (!\is_array($items) || !array_is_list($items)) {
            $this->errors[] = \sprintf('"%s" must be a list.', $key);

            return [];
        }

        $objects = [];
        foreach ($items as $i => $item) {
            $object = \is_array($item) ? array_filter($item, \is_string(...), \ARRAY_FILTER_USE_KEY) : null;
            if (null === $object || \count($object) !== \count($item)) {
                $this->errors[] = \sprintf('%s[%d] must be an object.', $key, $i);
                continue;
            }
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * Parents before children, whatever the order in the file.
     *
     * @param list<array<string, mixed>> $domains
     *
     * @return list<array<string, mixed>>
     */
    private function sortByParent(array $domains): array
    {
        $byCode = [];
        foreach ($domains as $i => $domain) {
            $byCode[self::str($domain, 'code') ?? '#'.$i] = $domain;
        }
        $sorted = [];
        $visit = function (string $code, array $stack) use (&$visit, &$sorted, $byCode): void {
            if (isset($sorted[$code]) || !isset($byCode[$code])) {
                return;
            }
            if (\in_array($code, $stack, true)) {
                $this->errors[] = \sprintf('domain "%s": cycle in parents.', $code);

                return;
            }
            $parent = self::str($byCode[$code], 'parent');
            if (null !== $parent) {
                $visit($parent, [...$stack, $code]);
            }
            $sorted[$code] = $byCode[$code];
        };
        foreach (array_keys($byCode) as $code) {
            $visit((string) $code, []);
        }

        return array_values($sorted);
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function user(array $ref, string $label): ?User
    {
        $username = self::str($ref, 'user');
        $externalId = $ref['external_user_id'] ?? null;
        $user = match (true) {
            null !== $username => $this->users->findOneByUsername($username),
            \is_string($externalId) || \is_int($externalId) => $this->users->findOneBy(['externalUserId' => (string) $externalId]),
            default => null,
        };
        if (null === $user) {
            $this->errors[] = \sprintf('%s: unknown user "%s".', $label, $username ?? (\is_scalar($externalId) ? (string) $externalId : ''));
        }

        return $user;
    }

    /**
     * @param array<string, true> $knownTypes
     */
    private function expression(mixed $value, string $label, array $knownTypes): ?AccessExpression
    {
        if (!\is_string($value)) {
            $this->errors[] = \sprintf('%s: rights must be a string.', $label);

            return null;
        }
        try {
            $expression = AccessExpression::parse($value);
        } catch (InvalidAccessExpression $e) {
            $this->errors[] = \sprintf('%s: %s', $label, $e->getMessage());

            return null;
        }
        foreach ($expression->namedTypes() as $type) {
            if (!isset($knownTypes[$type]) && !\array_key_exists($type, ResourceType::RESERVED)) {
                $this->errors[] = \sprintf('%s: unknown resource type "%s".', $label, $type);
            }
        }

        return $expression;
    }

    /**
     * A domain checked during the validation phase: it exists (or has just been created).
     */
    private function domain(string $code): Domain
    {
        return $this->domains->findByCode($code) ?? throw new \LogicException(\sprintf('Domain "%s" vanished during the import.', $code));
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function str(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
