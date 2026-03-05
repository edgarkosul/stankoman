<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('import_media_issues');
        Schema::dropIfExists('product_import_media');
    }
};
