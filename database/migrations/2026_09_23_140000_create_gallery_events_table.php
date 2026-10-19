<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ürün Galerisi (CRO) olayları: view (galeri çizildi) | swipe (ana görsel değişti) | thumb (küçük görsel)
 * | open (tam ekran inceleme) | zoom (yakınlaştırma yapıldı) | close | error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->unsignedBigInteger('product_id')->nullable(); // platform ürün id (Ticimax productId)
            $table->unsignedSmallInteger('image_index')->default(0);
            $table->unsignedSmallInteger('image_count')->default(0);
            $table->string('value', 160)->nullable();             // zoom: ölçek; error: mesaj
            $table->string('page_path', 200)->nullable();
            $table->string('device', 8)->nullable();              // m|d
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_events');
    }
};
