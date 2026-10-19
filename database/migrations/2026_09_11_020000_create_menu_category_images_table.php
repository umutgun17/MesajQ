<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Akıllı Menü kategori kapakları: Akıllı Sıralama'nın üst ürünleri arasından görsel puanıyla seçilen kapak + adaylar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_category_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('category_path', 300);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->decimal('score', 5, 3)->nullable();
            $table->json('candidates')->nullable();
            $table->timestamp('picked_at')->nullable();

            $table->unique(['tenant_id', 'category_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_category_images');
    }
};
