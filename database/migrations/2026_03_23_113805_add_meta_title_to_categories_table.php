<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'meta_title')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->string('meta_title')->nullable()->after('order');
            });
        }

        DB::table('categories')
            ->where(function ($query): void {
                $query->whereNull('meta_title')
                    ->orWhere('meta_title', '');
            })
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->update([
                'meta_title' => DB::raw('name'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('categories', 'meta_title')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('meta_title');
        });
    }
};
