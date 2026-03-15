<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Drivers\ImportDriverRegistry;
use Illuminate\Database\Seeder;

class SupplierImportSourceSeeder extends Seeder
{
    public function run(ImportDriverRegistry $drivers): void
    {
        $defaults = [
            'vactool' => [
                'name' => 'Основной HTML',
                'driver_key' => 'vactool_html',
                'sort' => 10,
            ],
            'metalmaster' => [
                'name' => 'Основной HTML',
                'driver_key' => 'metalmaster_html',
                'sort' => 10,
            ],
            'metaltec' => [
                'name' => 'Основной XML',
                'driver_key' => 'metaltec_xml',
                'sort' => 10,
            ],
        ];

        foreach ($defaults as $supplierSlug => $definition) {
            $supplier = Supplier::query()->where('slug', $supplierSlug)->first();

            if (! $supplier instanceof Supplier) {
                continue;
            }

            $driver = $drivers->get($definition['driver_key']);

            if ($driver === null) {
                continue;
            }

            SupplierImportSource::query()->updateOrCreate(
                [
                    'supplier_id' => $supplier->id,
                    'name' => $definition['name'],
                ],
                [
                    'driver_key' => $driver->key(),
                    'profile_key' => $driver->profileKey(),
                    'settings' => $driver->defaultSettings(),
                    'is_active' => true,
                    'sort' => $definition['sort'],
                ],
            );
        }
    }
}
