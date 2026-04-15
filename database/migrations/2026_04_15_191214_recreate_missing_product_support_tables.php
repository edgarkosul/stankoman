<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_categories')) {
            Schema::create('product_categories', function (Blueprint $table): void {
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('category_id');
                $table->boolean('is_primary')->default(false);

                $table->primary(['product_id', 'category_id']);
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
                $table->index(['category_id', 'is_primary']);
            });
        }

        if (! Schema::hasTable('product_supplier_references')) {
            Schema::create('product_supplier_references', function (Blueprint $table): void {
                $table->id();
                $table->string('supplier', 120);
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->string('external_id');
                $table->unsignedInteger('source_category_id')->nullable();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('first_seen_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
                $table->foreignId('last_seen_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['supplier_id', 'external_id'], 'product_supplier_reference_supplier_entity_unique');
                $table->index(['supplier', 'external_id'], 'product_supplier_reference_supplier_external_idx');
                $table->index(['supplier', 'product_id'], 'product_supplier_reference_supplier_product_idx');
                $table->index(['supplier', 'last_seen_run_id'], 'product_supplier_reference_supplier_run_idx');
                $table->index(
                    ['supplier', 'source_category_id'],
                    'product_supplier_reference_supplier_source_category_idx',
                );
                $table->index(
                    ['supplier_id', 'product_id'],
                    'product_supplier_reference_supplier_entity_product_idx',
                );
            });
        }

        if (! Schema::hasTable('product_import_media')) {
            Schema::create('product_import_media', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->nullable()->constrained('import_runs')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->text('source_url');
                $table->char('source_url_hash', 64);
                $table->string('source_kind', 24)->default('image');
                $table->string('status', 16)->default('pending');
                $table->string('mime_type', 191)->nullable();
                $table->unsignedBigInteger('bytes')->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->string('local_path')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['product_id', 'source_url_hash'], 'product_import_media_product_source_unique');
                $table->index(['status', 'created_at'], 'product_import_media_status_created_idx');
                $table->index('source_url_hash', 'product_import_media_source_hash_idx');
                $table->index('content_hash', 'product_import_media_content_hash_idx');
            });
        }

        if (! Schema::hasTable('import_media_issues')) {
            Schema::create('import_media_issues', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('media_id')->nullable()->constrained('product_import_media')->nullOnDelete();
                $table->foreignId('run_id')->nullable()->constrained('import_runs')->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('code', 64);
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'product_id'], 'import_media_issues_run_product_idx');
                $table->index('code', 'import_media_issues_code_idx');
            });
        }
    }

    public function down(): void
    {
        // Recovery migration: keep restored tables in place on rollback.
    }
};
