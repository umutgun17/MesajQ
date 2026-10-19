<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->date('date');
            $table->integer('total_conversations')->default(0);
            $table->integer('answered')->default(0);
            $table->integer('unanswered')->default(0);
            $table->integer('avg_response_time_seconds')->nullable();
            $table->integer('ai_used_count')->default(0);
            $table->integer('ai_approved_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'channel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_stats');
    }
};
