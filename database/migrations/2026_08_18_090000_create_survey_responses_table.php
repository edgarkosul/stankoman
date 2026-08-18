<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('survey_responses')) {
            return;
        }

        Schema::create('survey_responses', function (Blueprint $table): void {
            // Ответы на внутренние анкеты админки (сейчас — анкета по ценам).
            $table->id();
            $table->string('survey', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('answers');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
