<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_training_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('source_type', ['conversation', 'manual']);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('channel_type')->nullable();
            $table->text('question');
            $table->text('answer');
            $table->jsonb('context')->nullable();
            $table->decimal('quality_score', 3, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('used_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'channel_type', 'is_active']);
            $table->index(['tenant_id', 'source_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_data');
    }
};
