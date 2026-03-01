<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_company')->default(false)->after('remember_token');
            $table->string('company_name')->nullable()->after('is_company');
            $table->string('inn', 12)->nullable()->after('company_name');
            $table->string('kpp', 9)->nullable()->after('inn');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'is_company',
                'company_name',
                'inn',
                'kpp',
            ]);
        });
    }
};
