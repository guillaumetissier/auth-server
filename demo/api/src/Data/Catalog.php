<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The resources of the demo, in memory. Each one belongs to a domain of the auth server: the API
 * decides access by comparing that domain with the rights carried by the token.
 *
 * Domains created by "make seed": a1 (Group) > a10 (North) > a100 (North depot), and a1 > a11 (South).
 */
final class Catalog
{
    /** @var array<string, array{id: string, model: string, plate: string, price: string, photos: string, domain: string}> */
    public const VEHICLES = [
        'V-1001' => ['id' => 'V-1001', 'model' => 'Renault Clio', 'plate' => 'AB-123-CD', 'price' => '14 990 EUR', 'photos' => '6 photos', 'domain' => '/a1/a10/'],
        'V-1002' => ['id' => 'V-1002', 'model' => 'Peugeot 208', 'plate' => 'EF-456-GH', 'price' => '17 450 EUR', 'photos' => '4 photos', 'domain' => '/a1/a10/a100/'],
        'V-2001' => ['id' => 'V-2001', 'model' => 'Volkswagen Golf', 'plate' => 'IJ-789-KL', 'price' => '21 900 EUR', 'photos' => '8 photos', 'domain' => '/a1/a11/'],
    ];

    /**
     * Fields of a vehicle that belong to a facet: "vehicle.pricing:w" lets a user change the price only.
     * The other fields (model, plate) belong to the vehicle itself. The auth server only knows the facet
     * names; this mapping is the API's own.
     */
    public const VEHICLE_FACETS = ['price' => 'pricing', 'photos' => 'media'];

    /** @var array<string, array{id: string, customer: string, amount: string, domain: string}> */
    public const INVOICES = [
        'F-0001' => ['id' => 'F-0001', 'customer' => 'Garage Martin', 'amount' => '1 250.00 EUR', 'domain' => '/a1/a10/'],
        'F-0002' => ['id' => 'F-0002', 'customer' => 'Auto Sud', 'amount' => '980.00 EUR', 'domain' => '/a1/a11/'],
    ];
}
