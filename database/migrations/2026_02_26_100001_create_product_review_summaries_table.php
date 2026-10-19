<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_review_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->bigInteger('trendyol_content_id')->index();
            $table->decimal('avg_rate', 3, 2)->default(0);
            $table->integer('rate_count')->default(0);
            $table->integer('comment_count')->default(0);
            $table->integer('rate_five')->default(0);
            $table->integer('rate_four')->default(0);
            $table->integer('rate_three')->default(0);
            $table->integer('rate_two')->default(0);
            $table->integer('rate_one')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_review_summaries');
    }
};
