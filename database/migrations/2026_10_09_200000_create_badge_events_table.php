<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ürün Rozetleri olayları: list_view (sayfadaki kart/rozet sayıları), click (rozetli/rozetsiz kart tıklaması), pdp_view
        Schema::create('badge_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('session_id', 64)->nullable();
            $t->string('type', 16);
            $t->string('product_id', 32)->nullable();
            $t->string('badge', 40)->nullable();      // click/pdp_view: ilk (görünen en öncelikli) rozet; rozetsizse null
            $t->jsonb('counts')->nullable();          // list_view: {total, badged, by:{typeId:n}}
            $t->string('page_path', 200)->nullable();
            $t->char('device', 1)->nullable();
            $t->boolean('is_test')->default(false);
            $t->timestamp('created_at')->nullable();
            $t->index(['tenant_id', 'created_at']);
            $t->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_events');
    }
};
