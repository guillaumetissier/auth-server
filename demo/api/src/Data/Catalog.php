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
    /** @var array<string, array{id: string, model: string, plate: string, domain: string}> */
    public const VEHICLES = [
        'V-1001' => ['id' => 'V-1001', 'model' => 'Renault Clio', 'plate' => 'AB-123-CD', 'domain' => '/a1/a10/'],
        'V-1002' => ['id' => 'V-1002', 'model' => 'Peugeot 208', 'plate' => 'EF-456-GH', 'domain' => '/a1/a10/a100/'],
        'V-2001' => ['id' => 'V-2001', 'model' => 'Volkswagen Golf', 'plate' => 'IJ-789-KL', 'domain' => '/a1/a11/'],
    ];

    /** @var array<string, array{id: string, customer: string, amount: string, domain: string}> */
    public const INVOICES = [
        'F-0001' => ['id' => 'F-0001', 'customer' => 'Garage Martin', 'amount' => '1 250.00 EUR', 'domain' => '/a1/a10/'],
        'F-0002' => ['id' => 'F-0002', 'customer' => 'Auto Sud', 'amount' => '980.00 EUR', 'domain' => '/a1/a11/'],
    ];
}
