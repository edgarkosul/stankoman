<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('products');
            $table->string('status')->default('pending');
            $table->json('columns')->nullable();
            $table->json('totals')->nullable();
            $table->string('source_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'id']);
            $table->index('status');
        });

        Schema::create('import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('import_runs')->cascadeOnDelete();
            $table->integer('row_index')->nullable();
            $table->string('code', 64);
            $table->string('severity', 16)->default('error');
            $table->text('message')->nullable();
            $table->json('row_snapshot')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'row_index']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
        Schema::dropIfExists('import_runs');
    }
};
