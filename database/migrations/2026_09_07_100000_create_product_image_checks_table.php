<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ürün görsel URL'lerinin CANLILIK kaydı.
 *
 * Katalogda `products.images` dolu olduğu hâlde URL'nin kendisi CDN'de 404
 * dönebiliyor (Ticimax feed'i silinmiş dosyanın adresini vermeye devam ediyor).
 * "Görseli var" filtresi (jsonb_array_length > 0) bunu göremediğinden arama
 * sonucunda placeholder'lı = fotoğrafsız görünen kartlar çıkıyordu.
 *
 * `search:verify-images` bu tabloyu kullanır: sağlam URL'i tekrar tekrar
 * yoklamamak (ok_ttl) ve bir URL'in ne zamandır ölü olduğunu bilmek için.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_checks', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 32)->unique();   // md5(url)
            $table->text('url');
            $table->smallInteger('status')->default(0); // HTTP kodu (0 = ağ hatası)
            $table->boolean('is_dead')->default(false);
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->timestamp('first_dead_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['is_dead', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_checks');
    }
};
