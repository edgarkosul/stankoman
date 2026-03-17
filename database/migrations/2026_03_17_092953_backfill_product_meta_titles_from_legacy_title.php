<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumns('products', ['title', 'meta_title'])) {
            return;
        }

        DB::table('products')
            ->where(function ($query): void {
                $query->whereNull('meta_title')
                    ->orWhere('meta_title', '');
            })
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->update([
                'meta_title' => DB::raw('title'),
            ]);
    }

    public function down(): void
    {
        //
    }
};
