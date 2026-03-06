<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_feed_sources', function (Blueprint $table) {
            $table->id();
            $table->string('supplier', 120);
            $table->string('source_type', 16);
            $table->string('fingerprint', 64);
            $table->string('source_url', 2048)->nullable();
            $table->string('stored_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['supplier', 'fingerprint'], 'import_feed_sources_supplier_fingerprint_unique');
            $table->index(['supplier', 'source_type'], 'import_feed_sources_supplier_type_idx');
            $table->index(['supplier', 'last_used_at'], 'import_feed_sources_supplier_last_used_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_feed_sources');
    }
};
