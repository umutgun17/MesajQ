<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ürün Videoları: Yunbi'de barındırılan ürün videoları ve platforma (Ticimax) yazım durumu.
 * status          = dosyanın durumu (pending → processing → ready | failed)
 * platform_status = mağazadaki durum (none | published | failed); bir üründe aynı anda tek video yayında olur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('product_id');
            $table->string('platform_product_id', 64);
            $table->string('status', 16)->default('pending');
            $table->string('original_name', 255)->nullable();
            $table->string('source_path', 512)->nullable();
            $table->string('output_path', 512)->nullable();
            $table->string('public_url', 512)->nullable();
            $table->string('poster_url', 512)->nullable();
            $table->decimal('duration_sec', 6, 2)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->jsonb('settings')->nullable();
            $table->string('platform_status', 16)->default('none');
            $table->timestamp('platform_synced_at')->nullable();
            $table->boolean('platform_verified')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'platform_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_videos');
    }
};
