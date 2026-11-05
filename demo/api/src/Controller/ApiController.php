<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\Catalog;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use GuillaumeTissier\ResourceGuard\Bridge\Symfony\ResourceGuardAuthenticator;
use GuillaumeTissier\ResourceGuard\Guard\Principal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The demo resources. Authentication (token, DPoP proof, read-only impersonation) is done by the
 * resource-guard firewall before these methods run; they only decide authorization: scopes and rights.
 * Nothing is stored: writes are simulated.
 */
#[Route('/api')]
final class ApiController
{
    #[Route('/public/status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'service' => 'demo-api', 'authentication' => 'none needed']);
    }

    /** Who is calling: what the API reads from the token. */
    #[Route('/me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $principal = $this->principal($request);

        return new JsonResponse([
            'user_id' => $principal->userId,
            'client_id' => $principal->clientId,
            'scopes' => $principal->scopes,
            'proof_of_possession' => $principal->proofOfPossession,
            'domain' => $principal->domain(),
            'acl' => $principal->token->claims['acl'] ?? [],
            'impersonation' => $principal->isImpersonated()
                ? ['actor_id' => $principal->actorId(), 'read_only' => $principal->isReadOnly()]
                : null,
            'expires_at' => $principal->token->expiresAt->format(\DATE_ATOM),
        ]);
    }

    #[Route('/vehicles', methods: ['GET'])]
    public function vehicles(Request $request): JsonResponse
    {
        return $this->readableList($this->principal($request), 'vehicle', Catalog::VEHICLES);
    }

    #[Route('/vehicles/{id}', methods: ['GET'])]
    public function vehicle(Request $request, string $id): JsonResponse
    {
        $vehicle = Catalog::VEHICLES[$id] ?? null;
        if (null === $vehicle) {
            return $this->problem(404, 'not_found', \sprintf('There is no vehicle %s.', $id));
        }

        $principal = $this->principal($request);

        return $this->deny($principal, 'vehicle', AccessLevel::Read, $vehicle['domain'], 'read vehicle '.$id)
            ?? new JsonResponse($this->visibleFields($principal, $vehicle));
    }

    /**
     * Changes some fields of a vehicle (simulated). Model and plate need write access to the vehicle; the
     * price and the photos need write access to their facet ("vehicle.pricing", "vehicle.media"), which
     * write access to the vehicle includes unless a facet rule says otherwise.
     */
    #[Route('/vehicles/{id}', methods: ['PATCH'])]
    public function updateVehicle(Request $request, string $id): JsonResponse
    {
        $vehicle = Catalog::VEHICLES[$id] ?? null;
        if (null === $vehicle) {
            return $this->problem(404, 'not_found', \sprintf('There is no vehicle %s.', $id));
        }
        $principal = $this->principal($request);
        $denied = $this->deny($principal, 'vehicle', AccessLevel::Read, $vehicle['domain'], 'change vehicle '.$id);
        if (null !== $denied) {
            return $denied;
        }

        $changes = json_decode($request->getContent(), true);
        $editable = ['model', 'plate', ...array_keys(Catalog::VEHICLE_FACETS)];
        if (!\is_array($changes) || [] === $changes || [] !== array_diff(array_keys($changes), $editable)) {
            return $this->problem(400, 'invalid_request', \sprintf('Send a JSON object with some of these fields: %s.', implode(', ', $editable)));
        }

        // Fields of the vehicle itself: write access to the vehicle (a facet right is not enough).
        $ownFields = array_values(array_diff(array_keys($changes), array_keys(Catalog::VEHICLE_FACETS)));
        if ([] !== $ownFields && !$principal->can('vehicle', AccessLevel::Write, $vehicle['domain'])) {
            return $this->problem(403, 'insufficient_rights', \sprintf(
                'To change %s you need write access to vehicles in %s; you hold %s there.%s',
                implode(', ', $ownFields),
                $vehicle['domain'],
                $principal->accessLevel('vehicle', $vehicle['domain'])->label(),
                $this->facetHint($principal, $vehicle['domain']),
            ), ['denied_fields' => $ownFields]);
        }

        // Fields of facets: write access to each facet. Never ignore a denied field silently.
        $facets = array_values(array_unique(array_map(static fn (string $field): string => Catalog::VEHICLE_FACETS[$field], array_values(array_intersect(array_keys($changes), array_keys(Catalog::VEHICLE_FACETS))))));
        $deniedFacets = $principal->deniedFacets('vehicle', $facets, AccessLevel::Write, $vehicle['domain']);
        if ([] !== $deniedFacets) {
            $fields = array_keys(array_filter(Catalog::VEHICLE_FACETS, static fn (string $facet, string $field): bool => \in_array($facet, $deniedFacets, true) && \array_key_exists($field, $changes), \ARRAY_FILTER_USE_BOTH));

            return $this->problem(403, 'insufficient_rights', \sprintf(
                'To change %s you need write access to %s in %s.',
                implode(', ', $fields),
                implode(', ', array_map(static fn (string $facet): string => 'vehicle.'.$facet, $deniedFacets)),
                $vehicle['domain'],
            ), ['denied_fields' => $fields, 'denied_facets' => array_map(static fn (string $facet): string => 'vehicle.'.$facet, $deniedFacets)]);
        }

        return new JsonResponse([
            'updated' => $id,
            'changes' => $changes,
            'note' => 'Simulated: nothing is stored.',
            'by' => $principal->userId,
        ]);
    }

    /** Creates a vehicle in the caller's own domain (simulated). */
    #[Route('/vehicles', methods: ['POST'])]
    public function createVehicle(Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        $domain = $principal->domain();
        if (null === $domain) {
            return $this->problem(403, 'insufficient_rights', 'You belong to no domain, so there is no domain to create the vehicle in.');
        }

        $denied = $this->deny($principal, 'vehicle', AccessLevel::Write, $domain, 'create a vehicle in your domain');
        if (null !== $denied) {
            return $denied;
        }

        $body = json_decode($request->getContent(), true);
        $model = \is_array($body) && \is_string($body['model'] ?? null) ? $body['model'] : 'New vehicle';

        return new JsonResponse([
            'created' => ['id' => 'V-'.random_int(3000, 9999), 'model' => $model, 'domain' => $domain],
            'note' => 'Simulated: nothing is stored.',
            'by' => $principal->userId,
            'on_behalf_of' => $principal->isImpersonated() ? ['actor_id' => $principal->actorId()] : null,
        ], 201);
    }

    /** Deleting needs the highest level, manage (simulated). */
    #[Route('/vehicles/{id}', methods: ['DELETE'])]
    public function deleteVehicle(Request $request, string $id): JsonResponse
    {
        $vehicle = Catalog::VEHICLES[$id] ?? null;
        if (null === $vehicle) {
            return $this->problem(404, 'not_found', \sprintf('There is no vehicle %s.', $id));
        }

        return $this->deny($this->principal($request), 'vehicle', AccessLevel::Manage, $vehicle['domain'], 'delete vehicle '.$id)
            ?? new JsonResponse(['deleted' => $id, 'note' => 'Simulated: nothing is deleted.']);
    }

    #[Route('/invoices', methods: ['GET'])]
    public function invoices(Request $request): JsonResponse
    {
        return $this->readableList($this->principal($request), 'invoice', Catalog::INVOICES);
    }

    /** Needs a scope the demo client is not granted: shows a scope refusal. */
    #[Route('/invoices/export', methods: ['GET'])]
    public function exportInvoices(Request $request): JsonResponse
    {
        $principal = $this->principal($request);
        if (!$principal->hasScope('invoices.export')) {
            return $this->problem(403, 'insufficient_scope', \sprintf(
                'This request needs the scope "invoices.export". The token was issued with: %s. Scopes are granted to the application (client), not to the user.',
                [] === $principal->scopes ? 'no scope' : implode(', ', $principal->scopes),
            ), ['required_scope' => 'invoices.export']);
        }

        return new JsonResponse(['export' => 'invoices.csv', 'note' => 'Simulated.']);
    }

    /**
     * Returns the resources the caller may read; 403 when he may read none of them.
     *
     * @param array<string, array{domain: string}&array<string, string>> $resources
     */
    private function readableList(Principal $principal, string $type, array $resources): JsonResponse
    {
        $items = [];
        foreach ($resources as $resource) {
            $level = $principal->accessLevel($type, $resource['domain']);
            if ($level->includes(AccessLevel::Read)) {
                $items[] = ('vehicle' === $type ? $this->visibleFields($principal, $resource) : $resource) + ['your_level' => $level->label()];
            }
        }

        if ([] === $items) {
            return $this->problem(403, 'insufficient_rights', \sprintf(
                'You may read no %s: your rights give no read access to %ss in any of their domains (%s).',
                $type,
                $type,
                implode(', ', array_unique(array_column($resources, 'domain'))),
            ), ['required' => ['type' => $type, 'level' => 'read']]);
        }

        return new JsonResponse([
            'items' => $items,
            'hidden' => \count($resources) - \count($items),
            'note' => 'Only the resources of domains where you hold read access are listed.',
        ]);
    }

    /**
     * The vehicle without the fields of facets the caller may not read ("vehicle:r vehicle.pricing:-" hides the price).
     *
     * @param array<string, string> $vehicle
     *
     * @return array<string, string>
     */
    private function visibleFields(Principal $principal, array $vehicle): array
    {
        $hidden = $principal->deniedFacets('vehicle', array_values(array_unique(Catalog::VEHICLE_FACETS)), AccessLevel::Read, $vehicle['domain']);

        return array_filter($vehicle, static fn (string $field): bool => !\in_array(Catalog::VEHICLE_FACETS[$field] ?? null, $hidden, true), \ARRAY_FILTER_USE_KEY);
    }

    /** For a caller holding facet rights only: which fields he may change. */
    private function facetHint(Principal $principal, string $domain): string
    {
        $writable = array_keys(array_filter(Catalog::VEHICLE_FACETS, static fn (string $facet): bool => $principal->can('vehicle.'.$facet, AccessLevel::Write, $domain)));

        return [] === $writable ? '' : \sprintf(' You may change: %s.', implode(', ', $writable));
    }

    /** A 403 response explaining the missing right, or null when the caller holds it. */
    private function deny(Principal $principal, string $type, AccessLevel $required, string $domain, string $action): ?JsonResponse
    {
        $held = $principal->accessLevel($type, $domain);
        if ($held->includes($required)) {
            return null;
        }

        $why = match (true) {
            $principal->isReadOnly() => 'You act in a read-only impersonation: your level never exceeds read.',
            null === $principal->domain() => 'You belong to no domain and hold no rights.',
            !str_starts_with($domain, $principal->domain()) => \sprintf('This resource is outside your domain (%s): nothing applies there.', $principal->domain()),
            default => 'Ask an administrator of the domain to grant it (administration pages of the auth server).',
        };

        return $this->problem(403, 'insufficient_rights', \sprintf(
            'To %s you need %s access to %ss in %s; you hold %s there. %s',
            $action,
            $required->label(),
            $type,
            $domain,
            $held->label(),
            $why,
        ), ['required' => ['type' => $type, 'level' => $required->label(), 'domain' => $domain], 'held' => $held->label()]);
    }

    /** @param array<string, mixed> $details */
    private function problem(int $status, string $error, string $description, array $details = []): JsonResponse
    {
        return new JsonResponse(['error' => $error, 'error_description' => $description] + $details, $status, ['Cache-Control' => 'no-store']);
    }

    private function principal(Request $request): Principal
    {
        $principal = $request->attributes->get(ResourceGuardAuthenticator::PRINCIPAL_ATTRIBUTE);
        if (!$principal instanceof Principal) {
            throw new \LogicException('The resource-guard firewall must protect /api.');
        }

        return $principal;
    }
}
