<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();

            // Platform kalem referansı
            $table->string('platform_line_id')->nullable()->comment('Ticimax SiparisUrunID / Nebim LineID');
            $table->string('platform_product_id')->nullable();
            $table->string('platform_variant_id')->nullable();

            // Ürün anlık görüntüsü
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('variant_info')->nullable();
            $table->string('image_url')->nullable();

            // Adetler
            $table->integer('qty_ordered')->default(1);
            $table->integer('qty_requested')->default(1)->comment('Müşterinin iade etmek istediği adet');
            $table->integer('qty_approved')->nullable()->comment('Personel/yönetici onayı sonrası adet');

            // Tutarlar (sunucuda hesaplanır)
            $table->decimal('unit_price', 12, 2)->default(0)->comment('KDV dahil birim fiyat');
            $table->decimal('line_discount_share', 12, 2)->default(0)->comment('Bu kaleme düşen indirim payı (kupon dağıtımı dahil)');
            $table->decimal('refundable_amount', 12, 2)->default(0)->comment('Talep edilen adet için net iade edilebilir tutar');

            // İade nedeni & durumu
            $table->string('reason_code')->nullable();
            $table->text('reason_note')->nullable();
            $table->string('item_condition')->nullable()->comment('Beyan: kullanılmamış/etiketli vb.');

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('return_request_id');
            $table->index(['tenant_id', 'platform_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_items');
    }
};
