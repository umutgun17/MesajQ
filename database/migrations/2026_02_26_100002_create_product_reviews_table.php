<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->bigInteger('trendyol_content_id')->index();
            $table->string('platform_review_id');
            $table->tinyInteger('rate')->default(0);
            $table->string('user_full_name')->nullable();
            $table->text('comment')->nullable();
            $table->string('comment_date_text')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->jsonb('images')->default('[]');
            $table->integer('like_count')->default(0);
            $table->text('seller_reply')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'platform_review_id'], 'product_reviews_product_platform_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
