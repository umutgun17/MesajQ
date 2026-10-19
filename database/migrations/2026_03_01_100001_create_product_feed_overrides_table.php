<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_feed_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('ai_title')->nullable();
            $table->text('ai_description')->nullable();
            $table->string('google_category')->nullable();
            $table->string('gender')->nullable();
            $table->string('age_group')->nullable();
            $table->string('material')->nullable();
            $table->string('custom_label_0')->nullable();
            $table->string('custom_label_1')->nullable();
            $table->boolean('is_excluded')->default(false);
            $table->timestamp('ai_optimized_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feed_overrides');
    }
};
