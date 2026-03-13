<?php

namespace App\Support\CatalogImport\Drivers;

enum DriverAvailability: string
{
    case Universal = 'universal';

    case SupplierSpecific = 'supplier_specific';

    case LegacyHidden = 'legacy_hidden';
}
