<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('import_runs')->cascadeOnDelete();
            $table->string('supplier', 120)->nullable();
            $table->string('stage', 32);
            $table->string('result', 32);
            $table->string('source_ref', 2048)->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedInteger('source_category_id')->nullable();
            $table->integer('row_index')->nullable();
            $table->string('code', 64)->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'stage', 'result']);
            $table->index(['run_id', 'external_id']);
            $table->index(['run_id', 'product_id']);
            $table->index(['run_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_run_events');
    }
};
