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

        $types = $this->list($data, 'resource_types');
        $domains = $this->sortByParent($this->list($data, 'domains'));
        $members = $this->list($data, 'members');
        $grants = $this->list($data, 'grants');

        // --- Check everything first ---
        $knownTypes = array_flip(array_map(static fn (ResourceType $t): string => $t->getCode(), $this->em->getRepository(ResourceType::class)->findAll()));
        foreach ($types as $i => $t) {
            if (!\is_string($t['code'] ?? null) || 1 !== preg_match(ResourceType::CODE_PATTERN, $t['code'])) {
                $this->errors[] = \sprintf('resource_types[%d]: invalid code.', $i);
                continue;
            }
            $knownTypes[$t['code']] = true;
        }

        $paths = [];
        foreach ($this->domains->findAll() as $existing) {
            $paths[$existing->getCode()] = $existing->getPath();
        }
        foreach ($domains as $i => $d) {
            $code = $d['code'] ?? null;
            $parent = $d['parent'] ?? null;
            if (!\is_string($code) || 1 !== preg_match(Domain::CODE_PATTERN, $code)) {
                $this->errors[] = \sprintf('domains[%d]: invalid code.', $i);
                continue;
            }
            if (null !== $parent && !isset($paths[$parent])) {
                $this->errors[] = \sprintf('domain "%s": unknown parent "%s".', $code, (string) $parent);
                continue;
            }
            $path = (null === $parent ? '/' : $paths[$parent]).$code.'/';
            if (isset($paths[$code]) && $paths[$code] !== $path) {
                $this->errors[] = \sprintf('domain "%s" exists at %s, the file puts it at %s: moving domains is not supported.', $code, $paths[$code], $path);
            }
            $paths[$code] = $path;
            if (isset($d['owner_rights'])) {
                $this->expression($d['owner_rights'], \sprintf('domain "%s" owner_rights', $code), $knownTypes);
            }
        }

        $membership = [];
        foreach ($members as $i => $m) {
            $user = $this->user($m, \sprintf('members[%d]', $i));
            $domain = $m['domain'] ?? null;
            if (null !== $domain && !isset($paths[$domain])) {
                $this->errors[] = \sprintf('members[%d]: unknown domain "%s".', $i, (string) $domain);
            }
            if (null !== $user) {
                $membership[$user->getUsername()] = null === $domain ? null : ($paths[$domain] ?? null);
            }
        }
        $memberPath = static fn (User $u): ?string => \array_key_exists($u->getUsername(), $membership) ? $membership[$u->getUsername()] : $u->getDomain()?->getPath();

        foreach ($domains as $d) {
            if (!\is_string($d['owner'] ?? null) || !isset($paths[$d['code'] ?? ''])) {
                continue;
            }
            $owner = $this->user(['user' => $d['owner']], \sprintf('domain "%s" owner', $d['code']));
            if (null !== $owner && null !== ($p = $memberPath($owner)) && !str_starts_with($paths[$d['code']], $p)) {
                $this->errors[] = \sprintf('domain "%s": owner %s belongs to %s, not to this domain or above.', $d['code'], $owner->getUsername(), $p);
            }
        }

        foreach ($grants as $i => $g) {
            $label = \sprintf('grants[%d]', $i);
            $user = $this->user($g, $label);
            $domain = $g['domain'] ?? null;
            if (!\is_string($domain) || !isset($paths[$domain])) {
                $this->errors[] = \sprintf('%s: unknown domain "%s".', $label, \is_string($domain) ? $domain : '');
                continue;
            }
            $this->expression($g['rights'] ?? null, $label, $knownTypes);
            if (null !== $user && null !== ($p = $memberPath($user)) && !str_starts_with($paths[$domain], $p)) {
                $this->errors[] = \sprintf('%s: %s belongs to %s, rights on %s would never apply.', $label, $user->getUsername(), $p, $paths[$domain]);
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

        // --- Write, in one transaction ---
        $this->em->wrapInTransaction(function () use ($types, $domains, $members, $grants): void {
            foreach ($types as $t) {
                $type = $this->em->find(ResourceType::class, $t['code']);
                null === $type ? $this->em->persist(new ResourceType($t['code'], (string) ($t['label'] ?? $t['code']))) : $type->setLabel((string) ($t['label'] ?? $type->getLabel()));
            }
            $this->em->flush();

            foreach ($domains as $d) {
                $domain = $this->domains->findByCode($d['code']);
                $name = (string) ($d['name'] ?? $d['code']);
                if (null === $domain) {
                    $this->manager->createDomain($d['code'], $name, null !== ($d['parent'] ?? null) ? $this->domains->findByCode($d['parent']) : null);
                } else {
                    $domain->setName($name);
                }
            }
            $this->em->flush();

            foreach ($members as $m) {
                $this->manager->moveUser($this->user($m, ''), null !== ($m['domain'] ?? null) ? $this->domains->findByCode($m['domain']) : null, null);
            }

            foreach ($domains as $d) {
                if (!\array_key_exists('owner', $d) && !isset($d['owner_rights'])) {
                    continue;
                }
                $domain = $this->domains->findByCode($d['code']);
                $owner = \array_key_exists('owner', $d) ? (null === $d['owner'] ? null : $this->user(['user' => $d['owner']], '')) : $domain->getOwner();
                $rights = isset($d['owner_rights']) ? AccessExpression::parse($d['owner_rights']) : $domain->getOwnerExpression();
                $this->manager->setOwner($domain, $owner, $rights, null);
            }

            foreach ($grants as $g) {
                $this->manager->grant($this->user($g, ''), $this->domains->findByCode($g['domain']), AccessExpression::parse($g['rights']), (bool) ($g['subdomains'] ?? false), null);
            }
        });

        $io->success('Imported '.$summary.'.');

        return Command::SUCCESS;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function list(array $data, string $key): array
    {
        $items = $data[$key] ?? [];
        if (!\is_array($items) || !array_is_list($items)) {
            $this->errors[] = \sprintf('"%s" must be a list.', $key);

            return [];
        }

        return array_values(array_filter($items, function ($item, int $i) use ($key): bool {
            if (!\is_array($item)) {
                $this->errors[] = \sprintf('%s[%d] must be an object.', $key, $i);
            }

            return \is_array($item);
        }, \ARRAY_FILTER_USE_BOTH));
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
        foreach ($domains as $d) {
            $byCode[(string) ($d['code'] ?? '')] = $d;
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
            $parent = $byCode[$code]['parent'] ?? null;
            if (\is_string($parent)) {
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
        $user = match (true) {
            \is_string($ref['user'] ?? null) => $this->users->findOneByUsername($ref['user']),
            \is_scalar($ref['external_user_id'] ?? null) => $this->users->findOneBy(['externalUserId' => (string) $ref['external_user_id']]),
            default => null,
        };
        if (null === $user && '' !== $label) {
            $this->errors[] = \sprintf('%s: unknown user "%s".', $label, (string) ($ref['user'] ?? $ref['external_user_id'] ?? ''));
        }

        return $user;
    }

    /**
     * @param array<string, true> $knownTypes
     */
    private function expression(mixed $value, string $label, array $knownTypes): void
    {
        if (!\is_string($value)) {
            $this->errors[] = \sprintf('%s: rights must be a string.', $label);

            return;
        }
        try {
            $expression = AccessExpression::parse($value);
        } catch (InvalidAccessExpression $e) {
            $this->errors[] = \sprintf('%s: %s', $label, $e->getMessage());

            return;
        }
        foreach ($expression->namedTypes() as $type) {
            if (!isset($knownTypes[$type]) && !\array_key_exists($type, ResourceType::RESERVED)) {
                $this->errors[] = \sprintf('%s: unknown resource type "%s".', $label, $type);
            }
        }
    }
}
