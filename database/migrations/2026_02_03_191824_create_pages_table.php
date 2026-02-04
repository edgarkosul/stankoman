<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();

            $table->string('title', 200);
            $table->string('slug', 200)->unique();

            // HTML из RichEditor (позже добавим sanitization)
            $table->longText('content')->nullable();

            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();

            // простая SEO-база (можно расширить потом)
            $table->string('meta_title', 200)->nullable();
            $table->string('meta_description', 300)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
