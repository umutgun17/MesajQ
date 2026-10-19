<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 404 Sayfası olayları: view (kırık URL görüldü), click (ürün/kategori/ana sayfa), search, game (mini oyun), coupon (kupon kopyalandı)
        Schema::create('notfound_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('session_id', 64)->nullable();
            $t->string('type', 16);
            $t->string('template', 20)->nullable();
            $t->string('target', 16)->nullable();      // click: matched | product | category | home | logo
            $t->string('product_id', 32)->nullable();   // products.id
            $t->string('query', 120)->nullable();       // search: aranan metin
            $t->string('page_path', 300)->nullable();   // kırık adres (yol + sorgu)
            $t->string('referrer', 300)->nullable();    // kırık linke nereden gelindi
            $t->smallInteger('matched')->nullable();    // view: "Bunu mu arıyordunuz?" ürün sayısı
            $t->char('device', 1)->nullable();
            $t->boolean('is_test')->default(false);
            $t->timestamp('created_at')->nullable();
            $t->index(['tenant_id', 'created_at']);
            $t->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notfound_events');
    }
};
