<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_response_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('prompt');
            $table->text('ai_response');
            $table->text('final_response')->nullable();
            $table->boolean('was_approved')->default(false);
            $table->boolean('was_edited')->default(false);
            $table->integer('response_time_ms')->nullable();
            $table->string('model_used')->nullable();
            $table->jsonb('training_data_ids')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'was_approved']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_response_logs');
    }
};
