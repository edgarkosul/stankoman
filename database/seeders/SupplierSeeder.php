<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $supplier) {
            Supplier::query()->firstOrCreate(
                ['slug' => $supplier['slug']],
                $supplier,
            );
        }
    }

    /**
     * @return array<int, array{name:string,slug:string,is_active:bool}>
     */
    private function defaults(): array
    {
        return [
            [
                'name' => 'Vactool',
                'slug' => 'vactool',
                'is_active' => true,
            ],
            [
                'name' => 'Metalmaster',
                'slug' => 'metalmaster',
                'is_active' => true,
            ],
            [
                'name' => 'Metaltec',
                'slug' => 'metaltec',
                'is_active' => true,
            ],
        ];
    }
}
